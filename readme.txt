=== Handik Booking App ===
Contributors: handik
Requires at least: 6.4
Requires PHP: 7.4
Stable tag: 2.1.21.2
License: Proprietary

Single-page booking application for Handik with local CRM, hosted ChatKit, silent returning-client recognition, Cal.com booking orchestration, and GitHub-powered plugin updates.

== Description ==

Handik Booking App turns the plugin itself into the booking experience.

Features:

* single-page multi-step booking wizard
* shortcode and Elementor widget embedding
* hosted ChatKit assistant step
* local CRM tables for contacts, addresses, requests, bookings, and login tokens
* silent returning-client recognition by phone number
* Cal.com booking URL routing and webhook sync
* GitHub release-based plugin updates with WordPress auto-update support

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Open `Handik Booking > App Settings`.
4. Configure OpenAI, Google Maps, Cal.com, email sender, and GitHub updater settings.
5. Add `[handik_booking_app]` to a page or use the Elementor widget.
6. Enable auto-updates for the plugin on the WordPress Plugins screen if desired.

== Changelog ==

= 2.1.21.2 =
* **Hotfix — independent audit on the Sprint 14a + 14b email work.** Six findings closed; no schema change, no settings change, no behaviour change for installs that haven't enabled the master toggles. Pure hardening of the send pipeline and the .ics builder.
* **P1 — HTML injection via placeholder substitution.** `Admin_Helpers::render_template()` does raw `str_replace`; the customer HTML body inserted `{{customer_name}}` / `{{address}}` / `{{from_name}}` / `{{cal_url}}` verbatim. A contact whose `full_name` was `<img src=x onerror="alert(1)">` (saved through the CRM) landed working markup in the customer's inbox; a malicious address could break out of `<strong>` tags and inject spoofed paragraph content; `{{cal_url}}` accepted `javascript:` schemes (`esc_url_raw` doesn't strip them) and rendered as a clickable link. Fixed by routing user-controlled scalar placeholders through `esc_html()` for HTML body, `esc_url()` for URL placeholders, and `sanitize_text_field()` for subject placeholders. Pre-rendered list tokens (`tasks_list_html`, `days_list_html`) — already built with per-item `esc_html()` inside `build_placeholders` — bypass the second escaping pass so `<ul>` markup survives.
* **P1 — UTF-8 corruption in `Ics_Builder::fold_line`.** RFC 5545's 75-octet line limit was implemented as a naive `substr($line, 0, 75)`, which can split a multi-byte UTF-8 codepoint mid-byte. Reproduction: a SUMMARY where `·` (U+00B7, `0xC2 0xB7`) lands at the boundary produced one line ending with `0xC2` and a continuation starting with `0xB7` — both lines failed `mb_check_encoding`. Fixed by backing off the cut point to the last valid UTF-8 codepoint boundary before the limit; defensive 3-byte max backoff handles 4-byte UTF-8 sequences.
* **P2 — CR/LF stripping on the From-name display value.** Defends against header injection if a future settings code path stops sanitizing the `email_from_name` setting. Practical risk was already low (today's settings sanitize_text_field strips newlines), but the From-header build site now strips CR/LF/NUL at the use site too. Defence in depth.
* **P2 — Idempotency rollback failure now logs.** `release_idempotency()` previously called `$wpdb->update()` and discarded the return value. A transient DB error during rollback would leave the `confirmation_email_sent_at` stamp set forever, blocking every subsequent retry on that booking — the exact thing plan §4.6 promised would NOT happen. Now the false return is caught and logged (with the table, row id, and `wpdb->last_error`) so the operator can see what went wrong.
* **P2 — Defensive zero-days skip in the project-flow trigger.** `confirm_schedule()` only fires after every day is persisted, so in practice `dispatch_for_project` always sees a non-empty days array. But sending a "your visit is confirmed" email with zero days listed (e.g. a future rollback edge that empties `list_days()` between the trigger and our hydration) would produce a confusing customer email and an empty `.ics`. Now skipped silently if the days array hydrates empty.
* **Audit-time triage that didn't need a code fix:** the customer-duplication-on-owner-fail rollback semantic is documented as an accepted tradeoff in the 14b changelog and matches plan §6. The TZID drift from plan §4.5 (we emit UTC `Z` instead of explicit VTIMEZONE blocks) is interoperable with Apple Calendar / Google Calendar / Outlook so no functional change. PHPStan: 12 errors in touched files, all pre-existing on the parent commit (verified by re-running on the parent SHA). PHPCS: at parity with the rest of the codebase per the project's documented "noisy baseline" policy.

= 2.1.21.1 =
* **Sprint 14b — owner-side booking notification + email-error surface.** Closes the second half of the email work that 14a started. No new files, no schema change; extends the existing `Notifications_Service` and adds one System info callout. Master toggle defaults OFF on upgrade so existing installs see no behavioural change.
* **Owner gets their own "new booking from Jane" email** on every confirmed booking — main flow, direct preset, and project work-days — independent of the customer-side toggle (you can run owner-only, customer-only, or both). Plain-text only (no HTML — overkill when you're emailing yourself); Reply-To is set to the customer's email so a quick "got it, see you Tuesday" lands directly with them.
* **New settings on App Setup → Customer notifications → "Owner booking notification" section:**
  - Toggle: *Notify the owner on every new booking* (defaults OFF).
  - Recipient address: defaults to your `email_from_address` if empty — useful if you want bookings going to a phone-pinned alias (`ops@…`) instead of your main inbox.
  - Subject + body templates with `{{placeholder}}` substitution. Owner-side ships with four extra placeholders the customer email doesn't expose: `{{customer_phone}}`, `{{customer_email}}`, `{{source_label}}` (which booking surface — Main SPA / Direct booking form / Project work-days), and `{{open_request_admin_link}}` (deep-link to the admin booking-detail page).
  - Owner-side gets its own *Send owner test email to me* button on the same Notifications tab so you can preview your template edits before going live, exactly like the customer-side test in 14a. Bypasses the toggle.
* **One combined idempotency stamp covers both sides.** The Sprint 14a `confirmation_email_sent_at` column is unchanged — owner + customer dispatches share it, so a Cal-webhook retry can't fire either email twice. If the customer-side send succeeds but the owner-side fails (e.g. SMTP throttle), the stamp rolls back and a manual retry re-fires both. Tradeoff: in that rare case the customer would receive a second copy. We accept it because (a) the rollback path only happens on `wp_mail` failure, which is itself rare, and (b) avoiding duplicate emails to the owner is more important than the edge-case duplicate to the customer.
* **`LAST_EMAIL_ERROR_OPTION` callout on the System info page.** Mirrors the Sprint 7 `LAST_ERROR_OPTION` migration callout — when `wp_mail` returns false on either side (customer or owner), the failure surfaces in a red callout above the migration callout with the side, source, recipient, and timestamp. Cleared automatically on the next successful customer-side send.
* **Build-placeholder pipeline grew four owner-only tokens** (listed above). They're harmless if accidentally used in a customer template (substituted out as the literal string). The Send-Test sample-data context now also populates them so the preview email renders identically to a real one.
* **Out-of-scope (still v2 work — see plan §12):** Cancellation / reschedule notices. Per-form-type subject overrides (one template per source for v1, owner edits globally). Action Scheduler-backed N-hour reminders. Admin "Resend confirmation" button on the booking-detail page (the System info callout + manual idempotency-stamp release covers manual retry for now). SPF / DKIM deliverability documentation — flagged as a follow-up in the readme.

= 2.1.21.0 =
* **Sprint 14a — branded customer booking-confirmation emails.** Plugin now ships its own confirmation email (HTML + plain-text alternative, with a `.ics` calendar attachment) directly to the customer after every new booking — main SPA Cal flow, Additional Forms direct preset, and Additional Forms project work-days flow. Owner-controlled subject + HTML body + plain-text body templates with `{{placeholder}}` substitution. Reply-To is configurable (defaults to the existing `email_from_address`). Master toggle defaults OFF on upgrade; nothing changes until the owner enables it.
* **REQUIRED before flipping the toggle: disable Cal.com's own confirmation email.** Otherwise customers receive two emails for every booking (Cal's + ours).
  1. Cal.com dashboard → **Event Types** → click each event type Handik routes to.
  2. Open the **Workflows** tab.
  3. Find the default `New Event Booking` workflow.
  4. Either delete it, or change its action away from `Send Email`.
  5. Save. Repeat for every event type.
* Then in WordPress: **Handik Booking → App Setup → Customer notifications → "Send our own confirmation emails"** (toggle on) → **Save**. Use the **Send test email** button to preview the rendered template (with sample data) before going live.
* **DB migration 1.5.1** (idempotent, safe to re-run). Adds nullable `confirmation_email_sent_at DATETIME` column to `handik_bookings`, `handik_direct_booking_requests`, and `handik_project_scheduling_requests`. The new `Notifications_Service` uses an atomic `UPDATE … WHERE id = %d AND confirmation_email_sent_at IS NULL` against the relevant table to dedupe — Cal-webhook retries can't fire a second email, and the parallel direct-flow capture vs. webhook paths can't both deliver.
* **New action `do_action( 'handik_booking_confirmed', $context )`.** Fires from all three booking-creation sites with a uniform context shape (source, contact, address, tasks, when, booking_url, request_id, cal_booking_uid, idempotency-table tuple). Owners can hook the same action from a custom plugin to ship their own Slack / SMS / etc. notification — same extensibility pattern as the existing `handik_booking_app_send_sms_code` action.
* **Project flow: one email per schedule, not per day.** The `.ics` attachment carries one VEVENT per confirmed day, so the customer gets the full picture in a single calendar invite.
* **`.ics` builder is RFC 5545 minimal subset.** Line-folded at 75 octets, CRLF endings, escape-on-output for `,`, `;`, `\`, and newlines. Tested against Apple Calendar, Google Calendar, and Outlook web. Attachment is tagged `text/calendar; method=REQUEST` so clients render it as a calendar invite instead of a generic file icon.
* **`wp_mail` failure handling.** If `wp_mail` returns false the idempotency stamp rolls back to NULL (so a manual retry can re-fire), `handik_booking_app_last_email_error` is persisted (Sprint 14b will surface it on System info), and the failure is logged with the recipient + source for triage. Booking row itself is unaffected — the email is opt-in.
* **Photos NOT included in the email.** Avoids Gmail's 25 MB attachment cap and keeps templates portable. Customer is welcome to ask if they want to see what was on file.
* **Out of this sprint (deferred to 14b):** Owner-side "new booking from Jane" notification email. The `LAST_EMAIL_ERROR_OPTION` callout on the System info page. Per-flow template overrides. Cancellation / reschedule notices. SMTP / DKIM / SPF guidance in the readme.
* `Auth_Service::send_message()` was refactored to use the existing `Admin_Helpers::render_template()` placeholder engine instead of an inline `str_replace` loop — same behaviour, one less duplicated implementation.

= 2.1.20.2 =
* **P0 hotfix — admin-created bookings now appear in the Bookings list.** Owner reported: clicked "+ Add booking" in the admin (Sprint 13 / 2.1.20), filled the form, picked a Cal.com slot, but the booking never showed up at `?page=handik-booking-app-bookings`. Root cause was deeper than the admin flow alone — *every* direct-booking-form submission (admin-initiated since 2.1.20.0, public-form-initiated since 2.1.9.0) lived only in `handik_direct_booking_requests` and was never mirrored into `handik_bookings`, which is the only table the unified Bookings list reads.
* **DB migration 1.5.0** (idempotent, safe to re-run):
  - `handik_bookings.job_request_id` becomes NULL-able. Direct bookings have no `handik_job_requests` row to point at — the assistant flow is what produces those. Existing rows keep their non-NULL `job_request_id` value.
  - New column `handik_bookings.direct_request_id BIGINT(20) UNSIGNED NULL` + index. Mirrors `handik_direct_booking_requests.id` for direct rows; stays NULL for main-SPA Cal bookings.
* **New `Bookings_Service::upsert_from_direct_capture( $direct_request_id, $payload, $status )`.** Mirrors a direct row into `handik_bookings`. Idempotent on `cal_booking_id` UNIQUE — calling it from both the leading-edge path (Cal embed `bookingSuccessful` → `Direct_Booking_Service::capture_booking`) and the trailing-edge path (`Webhook_Service::dispatch_direct`) is safe; whichever fires first wins, the other is a no-op refresh.
* **Wired in two places:**
  - `Direct_Booking_Service::capture_booking()` calls the upsert immediately after flipping the `direct_booking_requests` row OPENED → BOOKED. The booking shows up in the unified list within milliseconds of Cal confirming the slot, even before Cal's webhook lands.
  - `Webhook_Service::dispatch_direct()` calls the upsert after updating the `direct_booking_requests` row, so a booking that came in cold (without us holding an open Cal embed — e.g. customer used a public preset link) still gets mirrored.
* **Admin Bookings list renderers** (`decorate_bookings`, `booking_card`, `bookings_table_markup`, `render_detail`) now branch on whichever id is set:
  - `job_request_id IS NOT NULL` → main-SPA booking. Render: assistant transcript, photos, task summary from catalog ids, summary estimate.
  - `direct_request_id IS NOT NULL` → direct booking. Render: preset title as the "task" line, no photos, no transcript, "Service" detail block listing form preset / booking type / duration / source (`admin:bookings` vs public form). The CSS class `is-direct` is added to the card so we can later style direct rows differently if needed.
  - Customer + address are pulled from `handik_direct_booking_requests` (which carries `contact_id` + `address_id` directly).
* **Cascade delete extension.** When a contact is hard-deleted via Sprint 12's "Delete this person" flow, the cascade now also drops mirrored `handik_bookings` rows that point at the contact's `direct_booking_requests` rows (via `direct_request_id` IN (...)). Otherwise those mirror rows would linger as orphans pointing at deleted parents.
* **Backfill semantics.** Existing direct bookings completed BEFORE 2.1.20.2 stay invisible from the Bookings list — there's no retroactive mirror because we don't store enough Cal payload data to reconstruct one. They remain visible under Additional Forms admin as before. New bookings (and Cal status changes — reschedule, cancel — on existing direct bookings via webhook) start populating the mirror immediately. Owner can run the migration without losing or breaking any data.

= 2.1.20.1 =
* **Hotfix — independent audit on the 2.1.20.0 admin booking flow.** 11 fixes from a 20-finding QA pass. No new features; hardens the flow shipped yesterday.
* **F1+F2 (P1) — admin captures saved empty cal_booking_id / cal_booking_uid.** The admin JS posted `{capture_token, booking}` but `Forms_Rest_Api::capture_direct` reads `booking_payload`; the fallback handed `capture_booking()` the entire envelope as the "booking", so the id-extractors saw nothing useful. Local row flipped to BOOKED but with no Cal handle — anyone searching by Cal ID would miss it until the cal-webhook reconciled. Renamed the JS key to match the public form; cal_booking_id / cal_booking_uid land immediately now.
* **F4 (P1) — submit button could double-create requests.** `withButtonLoading` re-enabled the button on `.finally()`, so a second click after the Cal embed mounted POSTed `/admin/booking/new` again and inserted a duplicate `direct_booking_requests` row. Added a `draftCreated` latch + `is-frozen` CSS state on the form that visually dims and pointer-locks Steps 1+2 once the draft commits.
* **F5 (P1) — admin Cal embed didn't strip Cal-side deep-link params.** The public form's `parseCalEmbedConfig` drops `overlayCalendar` / `month` / `date` / `slot` / `embed` / `embed_origin` / `layout` (would otherwise force the embed into "no slots available"), and re-prefixes `phone` with `+`. Admin's `parseCalEmbedUrl` was a 4-line stub that did neither. Mirrored the public-form behaviour byte-for-byte; same `CAL_EMBED_DROP_PARAMS` shape.
* **F6 (P2) — client-side de-dupe of `bookingSuccessful`.** Cal.com sometimes fires the event twice; the public form has `state._captureSent`, admin had nothing. Server is already idempotent (capture_booking short-circuits on STATUS_BOOKED) so this only avoided a duplicate toast + redirect race, but it's a one-line fix and keeps the two surfaces in parity.
* **F7 (P2) — orphan row when preset has no Cal URL.** `admin_submit` inserted the request row, then `build_cal_url` returned '' (preset misconfigured), then the JS threw "Cal.com URL missing" — but the half-baked READY-status row stayed in the table forever. Now we delete the just-inserted row before returning the error, and the message names the preset-edit page so the operator knows where to fix it.
* **F8 (P2) — stale "+ New address" inputs after switching contacts.** `chooseContact` rebuilt the address dropdown but didn't reset the inline `[data-handik-address-full]` / `[data-handik-address-unit]` inputs or hide the sub-pane. So: pick A → "+ New address" → type "123 Main" → pick B → "123 Main" stayed visible. Reset on every contact change.
* **F9 (P2) — mode toggle preserved stale state.** Toggling Existing → New → Existing kept the chosen contact_id hidden input and any typed walk-in fields. Mode toggle is a deliberate "throw the previous attempt away" gesture; clear the inactive pane on `setMode` so a half-finished walk-in form can't be submitted alongside an existing-contact pick.
* **F10 (P2) — phone-format validation in admin "+ New customer".** `Contacts_Service::normalize_phone` is forgiving — `"abc"` becomes empty + saves NULL, `"123"` becomes `+123`, `"hi 4"` becomes `+4`. `admin_submit` now requires ≥10 digits before calling upsert; admin form's `<input type="tel">` carries `minlength="10" pattern=".*\d{10,}.*"` so the browser pre-checks too.
* **F11 (P2) — webhook re-flip protection.** `dispatch_direct`'s fallback path matches by `cal_booking_id` when metadata is missing, then calls `Direct_Booking_Service::update_status_by_uid` which used to blindly write whatever status came in. A Cal-side cancel-then-rebook sequence (or any stray webhook carrying just the UID) could resurrect a `cancelled` row to `booked`. Now `update_status_by_uid` refuses cancelled→booked transitions and logs a warning; the id-keyed primary path is untouched.
* **F12 (P3) — friendly label for `admin_initiated` client_type.** `Admin_Helpers::client_type_label` recognised `returning_client` / `new_client` and fell through for the new admin slug, leaking the raw string `"admin_initiated"` into any list / CSV that rendered it. Added a case → "Admin booking".
* **F3 (P2) — GC for orphan OPENED rows.** Mirroring `Project_Schedule_Service`'s `handik_booking_app_form_gc_abandoned` cron. New `handik_booking_app_direct_gc_abandoned` daily event drops `direct_booking_requests` rows that sat in `booking_opened` status for >24h with no `cal_booking_id` (i.e. customer or admin opened the embed and walked away). Bounded `LIMIT 500` per run; logs the count when it actually deletes anything.
* **Property declaration fix (PHPStan).** `Admin_Bookings::$booking_presets` was assigned in the constructor but never declared at the class level. Worked today thanks to PHP's dynamic-property tolerance, but PHP 8.2+ deprecates that and 9.0 makes it fatal. One-line `protected $booking_presets;` declaration.

= 2.1.20.0 =
* **Admin can now book on behalf of a customer.** New "+ Add booking" CTA on the Bookings list page and "📅 Book a visit" button on every Person detail page. The flow uses the same Cal.com inline embed the public Additional Forms direct-booking preset uses — no parallel infrastructure.
* **Admin booking page (`?page=handik-booking-app-bookings&action=new`).** Three-step form, all on one page:
  1. *Customer & address.* Toggle between "Existing customer" (search-as-you-type by name / phone / email; results carry the customer's saved addresses so the address picker populates without a second round trip) and "+ New customer" (full name, phone, email, address inputs).
  2. *Booking type.* Preset dropdown listing every enabled `direct_cal_booking` form preset. Project work-day presets are intentionally excluded from this MVP — admin scheduling for project flows is a follow-up.
  3. *Pick a slot.* Cal.com inline embed. On `bookingSuccessful` the embed's payload is POST'd to the existing public capture endpoint with the issued capture_token; the row flips from `OPENED` → `BOOKED` and the operator is redirected back to the bookings list.
* **The webhook flow is untouched.** When Cal fires the BOOKING_CREATED event, `Webhook_Service::dispatch_direct()` matches the row by the existing `metadata.handik_direct_request_id` exactly the same way as a public submission. Admin-created rows are tagged `source_url='admin:bookings'` and `client_type='admin_initiated'` so they can be filtered out of public stats / abandoned-cart cron later if needed.
* **REST surface added:**
  - `POST /handik-booking-app/v1/admin/booking/new` — accepts `{preset_slug, contact_id?, address_id?, full_name?, phone?, email?, address_full?, address_unit?}`, returns `{success, request_id, cal_booking_url, capture_token}`. Gated on the existing `handik_manage_bookings` cap.
  - `GET /handik-booking-app/v1/admin/contact/search?q=…` — name / phone-digits / email autocomplete; up to 10 hits with the customer's saved addresses pre-attached.
* **Service-layer addition.** New `Direct_Booking_Service::admin_submit($slug, $payload)` — sister of `submit()`. Difference: accepts `contact_id` / `address_id` shortcuts so a phone-call repeat customer doesn't get their saved details overwritten by the upsert path. Same Cal URL, same metadata, same capture_token semantics.
* **Drop programmatic `<h2>` focus on step transitions in the main public SPA.** Owner-reported: the focus outline on the step heading was distracting on every step change (the ring stayed until the user clicked anywhere). Sprint 10's a11y fix added `tabindex="-1"` + `heading.focus()` plus a polite live-region announcer; the announcer is enough for screen readers and the forms SPA never moved focus for parity reasons (mobile keyboard dismissal, screen-magnifier confusion). Main SPA now matches.

**Email surface (FYI, not a code change in this release).** The plugin sends exactly one email — the returning-client magic-link/OTP fired from `Auth_Service::send_message()` (the only `wp_mail()` call site in the codebase). Booking confirmation emails come from Cal.com itself, not us; we influence their content via the `cal_confirmation_note` setting which Cal.com includes in metadata + its own confirmation email. To send our own confirmation (branded, photos, total estimate, etc.), wire a `Notifications_Service` into `Bookings_Service::upsert_from_cal()` — not done in this release.

= 2.1.19.0 =
* **Hard-delete for People / Requests / Bookings (admin).** New "Danger zone" block at the bottom of each detail page lets the operator permanently wipe a record and every dependent row. Owner-requested for spam cleanup and right-to-be-forgotten requests — there is **no soft-delete**, **no audit-trail copy of the customer's data**, **no restore**.
* **New capability `handik_delete_data`.** Granted to the administrator role on activation, and granted at runtime to anyone who already holds `manage_options` (via the Sprint 8 `user_has_cap` filter) so existing sites work without role surgery. Editors / helpers with only `handik_manage_bookings` do **not** see the Delete buttons or the REST routes; they have to be granted the new cap explicitly. The Integrations cap-missing notice already shows the cap key in `<code>` so admins know what to type into a role-management plugin.
* **Cascade order (kept in `Handik_Booking_App_Cascade_Delete_Service`).** No table in this plugin uses `FOREIGN KEY` constraints, so every cascade walks its dependents in PHP:
  - **Booking** → clears the parent request's denormalized `cal_booking_id` / `cal_booking_url` back-pointer, then drops the row.
  - **Request** → drops `handik_messages` (transcript) → drops `handik_bookings` rows → calls `wp_delete_attachment( $att_id, true )` per photo (frees the actual files in `wp-content/uploads/handik-booking-app/contact-N/request-M/`) → drops the `handik_job_requests` row → `rmdir` the now-empty per-request upload subdir.
  - **Person** → recurses through every owned `job_requests` row (so the request-level photo cleanup still runs), drops `handik_login_tokens`, drops `handik_direct_booking_requests`, drops `handik_project_work_days` + `handik_project_scheduling_requests`, drops every `handik_addresses` row (active and soft-deleted), drops the `handik_contacts` row, `rmdir`s the per-contact upload subdir.
  - **Cal.com calendar** is intentionally NOT touched — owner-decided scope ("the visit happened; we're cleaning local DB"). To toggle later, call `Handik_Booking_App_Cal_API_Service::cancel_booking($cal_uid)` in `Cascade_Delete_Service::delete_booking()` after the audit-log entry.
* **Audit log entry written BEFORE the wipe.** Every cascade emits a `Logger::warning( 'admin hard-delete: <entity>', ['actor' => …, 'id' => …, 'counts' => …] )` so a partial failure mid-cascade still leaves a record of who did what (the wp_options-backed Logger flushes synchronously for warning-level entries).
* **Typed-confirmation modal.** Click-confirm modals are too easy to mash through on mobile, so the destructive button stays disabled until the operator types `DELETE` verbatim into the input. Mirrors industry-standard "type the repo name to confirm" patterns. Modal also uses a red title bar so it can't be confused with routine Edit / Add Note dialogs.
* **Pre-delete summary.** The Person danger zone shows the cascade count up front: "Cascade will also wipe: 3 addresses, 7 requests, 12 messages, 2 bookings, 5 photos, 1 project schedule, 4 login tokens." The Request danger zone shows messages / bookings / photos. The Booking danger zone is leaf-level. Counts come from new `Contacts_Service::count_dependents( $id )` and `Job_Requests_Service::count_dependents( $id )` helpers.
* **REST surface.** Three new DELETE routes, all gated on the new cap:
  - `DELETE /handik-booking-app/v1/admin/contact/{id}`
  - `DELETE /handik-booking-app/v1/admin/job-request/{id}`
  - `DELETE /handik-booking-app/v1/admin/booking/{id}`
  Each returns `{ success: true, summary: { contacts: 1, addresses: 3, … } }` so the JS toast can name what was actually swept.

= 2.1.18.0 =
* **Sprint 11 — P2/P3 cleanup from the v2.1.16.0 audits.** 11 focused clusters; no new features, no schema changes, no public-flow regressions. Branch: `claude/sprint-11-p2-p3-cleanup`.

**Customer-side:**
* **Hard-coded support email moved to `config.strings.supportEmail`.** Was leaking `alex@handik.pro` into the public SPA on the unsafe step and the ZIP-not-serviced inline error — installs with a different owner address would surface the wrong contact. Falls back to the historical address so existing setups still work.
* **`Loading…` glyph unified.** Mixed `Loading…` / `Loading...` / `Loading` across the photos CTA and busy-buttons; standardised on the single ellipsis character.
* **Photo dropzone copy aligned with error.** Prior copy said "Up to 8 files · Photos to 10 MB · Videos to 50 MB"; the over-cap error said "up to 8 photos or videos". Both now say "8 photos or videos" so the help text and the failure mode agree.
* **Restart modal fallbacks (Forms SPA).** When the i18n bag was empty, the dialog rendered with no title or body. Hard-coded English fallbacks ("Start over?" / "Your current entries will be cleared." / "Keep going" / "Yes, start over") so first-time admin installs aren't confused.
* **`inputAttrsForModel` dead-code cleanup.** Removed an unreachable duplicate `case 'contact.full_name'` that was kept "for grep safety" but tripped linters and confused readers.

**Admin-side:**
* **Dashboard polish.**
  - Empty stat cards (Today / Tomorrow / This week with count = 0) now also link to the filtered Bookings list — was a dead chip before.
  - "This week" preview row no longer duplicates "Today" — query window now starts after tomorrow so the card surfaces the next NEW piece of info (a Wed/Thu/Fri visit, not the same 8am job already shown above).
  - Action-needed chips with count = 0 are now clickable too (visually muted via `is-zero` but reachable). Useful for navigating back to a filtered list after the count drops to zero.
  - Month "Revenue" stat relabelled to **"Revenue ceiling (high estimate)"** + hover title with full explanation. Was reading like actual revenue and risked over-forecasting.
  - New "Refreshed Xs ago · **Refresh now**" header. The 60s transient was opaque — owner had no idea whether the counters reflected an action they just took. The link busts the transient via `?refresh=1` and re-renders fresh-from-DB.
* **People page polish.**
  - Filter chips ("All people / With bookings / Drafts only / No address") now preserve the active search query and `show_spam` toggle. Was dropping `q=Smith` on every chip click.
  - Mailto-body sprintf hardened against `%` characters in customer names — used `str_replace` instead of `sprintf` so a name like "50% off John" doesn't crash PHP.
  - Spam toggle is now bidirectional. Showing spam? "Hide spam contacts" link. Hiding spam? "Show N hidden" link as before. Was one-direction (`show_spam=1`) until you edited the URL by hand.
  - "Nothing here. Nice." replaced with neutral copy "No requests in this bucket right now." for tone consistency with the other admin empty states.
* **Catalog editor: diff-based save.** Was POST'ing the entire catalog on every blur, even with no actual change — tabbing through 30 fields could fire 30 identical save requests. New `lastSavedJson` snapshot; `scheduleSave` short-circuits when the serialized output is identical to last successful save, and the "Saving…" status pill stops flashing on no-ops.
* **Logs page UX.**
  - "Show debug" checkbox now auto-submits on toggle (was inert until the owner also tapped Apply).
  - Empty-state distinguishes "no logs anywhere" (fresh install) from "filtered to nothing" (over-eager filter); the latter shows a "Clear filters" button so the owner doesn't think the logger is broken.
* **Integrations notice now names the cap key.** A `MANAGE_BOOKINGS`-only user who hits the Integrations tab sees `<code>handik_manage_integrations</code>` so they (and their site admin) know exactly what to grant. Was just "Manage Handik integrations" (the human label) without the underlying cap string.
* **Sprint 8 format-unification leftover.** Project-day rows in the Additional Forms admin were printing raw ISO 8601 timestamps like `2026-05-08T14:00:00.000Z`. ISO is now converted to MySQL DATETIME (UTC) before going through `Admin_Helpers::format_short()`, so the table reads like the rest of the admin (`Mon, May 8 · 2:00 PM`).
* **Photo lightbox keyboard accessibility.** Added an explicit `×` close button (44×44 px, focus-ring) and an Escape handler. Was mouse-click-on-backdrop only — iPad users with a Bluetooth keyboard got stuck. Focus restores to the trigger element on close.
* **Debounced search no longer jumps the page to top.** Stash the current `pageYOffset` to `sessionStorage` before submitting; restore on `DOMContentLoaded` and clear so the value doesn't haunt unrelated navigation. Owner reading a long bookings list keeps their scroll position when typing into the search field.
* **Settings sections remember their open/closed state.** Sprint 10's `initDetailsMemory()` only honored "open" from storage — once an owner collapsed a long section, the next page load reverted to the markup default (almost always `open`). Now closed state persists too. App Setup sections opt into the same memory via `data-handik-details-key="settings-<slug>"`, so a 9-section Booking-flow tab no longer re-opens everything every time.

= 2.1.17.0 =
* **Sprint 10 — full P0 + P1 batch from the v2.1.16.0 customer + admin UX audits.** ~50 findings closed across 10 customer-side clusters and 9 admin-side clusters; 2 commits on branch `claude/sprint-10-customer-admin-p0-p1`.

**Customer-side (main + Additional Forms SPAs):**
* **OTP "Resend in Xs" countdown auto-ticks.** Was frozen at render time — customer stared at "27s" forever until typing. 1-second `setInterval` in `bind()` surgically updates the resend-pending span; clears itself on step change or expiry. Same fix in both SPAs.
* **Photo upload size cap enforced before XHR.** 25 MB iPhone videos used to upload fully on 3G then get rejected server-side, file gone, generic toast. New per-file pre-flight: 10 MB images / 50 MB videos, lists offending names + size on rejection. Photo remove button bumped from 28×28 → 44×44 px to clear WCAG 2.5.5.
* **ZIP out-of-area now a persistent inline error.** Was a 4.2-second auto-dismiss toast that left the customer staring at a Continue button that did nothing. Now flips the address field to invalid state with a stable message naming the offending ZIP; clears when the customer re-types or picks a new Places result.
* **Restart preserves the 30-day verified-client cache.** Was unconditionally clearing it — a customer who tapped Restart mid-OTP lost their cached identity and had to OTP again. Restart now keeps `verifiedToken` / `verifiedPhone` / `verifiedProfile` / `phoneVerified` by default; explicit Sign-Out path (`executeRestart({signOut:true})` / `restart({signOut:true})`) wipes them. Also clears the assistant "we got stuck" banner. Forms SPA restart of a verified user lands on `details`, not `phone`.
* **Returning-customer back navigation no longer dead-ends.** Sprint 9 hid `contact_details` + `otp_verify` from the verified-user timeline, but Back-from-address still tried to land on them — silently invalidating cached profile if the customer edited the phone there. Back from `address_details` now goes to `photos` for verified customers (their actual previous step). Forms `details-back` for cache-restored users goes to `phone` (was `otp`, which fired a fresh `/phone-verify/start` and duplicated the SMS via Twilio rate-limit).
* **Cal.com embed reliability.** Three sub-fixes:
  1. The 15-second slow-load fallback used to check `calEmbedMountKey` (which flips synchronously after the inline call returned) — missed the actual failure mode where the script loaded but the iframe never rendered. New `calEmbedReadyKey` set in the `bookerReady` listener; fallback fires when reader-key isn't set after 15s.
  2. Cal listeners were registered on BOTH `window.Cal` and `calApi`, doubling the fire rate; consolidated to `calApi` only and added a client-side idempotency guard on `captureBookingSuccess` so the same booking-id only surfaces a toast once.
  3. Forms SPA timeout no longer clobbers the entire container with a bare "Open in new tab" button — INSERTS a notice ABOVE the existing skeleton/iframe so a late-arriving script doesn't fight it.
* **Forms `'pick-back'` action no longer dead-ends.** Sprint 5 renamed `address` → `details` (combined contact + address into one step) but this transition was missed; project-day picker's Back button landed on a non-existent step rendering a blank `genericError`. Owner-reported P0.
* **Welcome-back toast on cache restore.** `tryRestoreVerifiedClient` now queues a deferred toast that fires after first render (avoiding the loading overlay clobbering it); gated on `isReturningClient` so new-client cached tokens don't get a misleading greeting.
* **Focus management + modal a11y.**
  - `focusStepHeading` actually moves focus now (was only removing tabindex from a heading that never had one). New polite ARIA-live announcer for step changes.
  - Restart modal: ESC dismisses, backdrop click dismisses. Was missing entirely on both SPAs — mobile users on 320px had no X.
  - `prefers-reduced-motion` guard on the goTo smooth-scroll. CSS respected the OS pref elsewhere; JS didn't.
* **OTP error differentiates wrong-code from rate-limit lockout.** Wrong-code stays red ("That code is invalid or expired"); rate-limit response from `Auth_Service` surfaces with amber styling + dedicated copy "Too many verification attempts. Try again in a few minutes." so the customer doesn't keep mashing the keypad against a backend that's already locked them out.

**Admin-side:**
* **Bookings list pagination + status filter alignment.** Was capped at 500 rows total with no paging UI — at 10k bookings the owner silently lost everything older than ~9 months. Cap raised to 2000; new prev/next nav with "X of Y" summary; filters preserved across pages. Status filter realigned to the Sprint 8 pill taxonomy: `Booked` (blue), `Confirmed` (green), `Completed` (teal) are now separate filter chips; legacy "On the schedule" chip is the union of Booked + Confirmed for the prior mental model. Filter + page state preserved across booking-detail "Back" via `from_*` query params.
* **Migration error visibility.** Run-migrations REST response (Sprint 7's `{success, ran[], skipped, error, no_changes}`) now surfaces in the UI: System info shows `LAST_ERROR_OPTION` in a red callout if the previous run failed, plus a new "Last attempt" timestamp; the JS toast on the System info button reports "Migrations applied: …", "No pending migrations", "Skipped — another in progress", or the actual error message instead of always claiming green.
* **Mark Completed / Cancel patch DOM instead of full reload.** Was costing 2-5s of cellular flicker for a near-no-op action from the truck. Now the sticky bar's status pill flips inline + the action button disables to prevent double-tap; toast confirms.
* **Bookings search debounce 350ms → 600ms.** Less aggressive full-page submission on every keystroke; full AJAX search deferred (would require a server-rendered fragment endpoint).
* **Bottom nav 6 → 5 items, sub-page padding fixed.** Forms entry consolidated into Setup (presets stay reachable from Setup → tabs and from the top WP submenu). The bottom-nav padding-bottom selector was using `body.toplevel_page_*` only, which ONLY matches the dashboard — sub-pages get `body.handik-booking_page_*` and were never matching, so content hid under the fixed nav. New attribute-prefix selector covers both forms.
* **Catalog editor robustness.** Was filtering out rows that didn't have BOTH `id` AND `label`, so an owner who tabbed out mid-edit silently lost the row on auto-save. Now keeps partial rows where the user has started typing in either id or label. Delete confirmation is now ref-aware: when the task has `in use by N requests`, the modal names the count and uses different copy ("Remove anyway") so click-confirm-gone is no longer accidental. Group delete also uses the unified modal instead of `window.confirm`.
* **People page hardening.**
  - Address delete modal copy now explicitly says soft-delete (server already does it via `deleted_at`, but the prior modal said "Delete?" which made the owner think past bookings would corrupt).
  - Add-person form gained `minlength` + `pattern` hints on phone (10-digit) and `minlength` on full_name, so a typo is caught client-side before the round-trip.
  - Person edit `<details>` now persists open/closed state per tab via `sessionStorage` (data-handik-details-key), so a refresh doesn't snap the form shut.
* **Logs page mobile.** Filter row collapses to a `<details>` toggle on mobile (auto-opens when any filter is active so the customer sees the current scope). Pagination at 50/page replaces the dump-all-2000-cards behavior; prev/next nav preserves all filters.
* **"Add new preset" CTA on Additional Forms.** Owner-reported P1 dead-end — the only path to create a new preset was via WP-CLI / SQL. New button → slug + form-type picker → `Booking_Presets_Service::insert_blank()` (new method) → redirect into the full edit form for the rest of the fields. Slug uniqueness validated server-side.
* **Sticky action bar mobile fix.** Was using `top: var(--wp-admin--admin-bar--height, 32px)` — but vanilla wp-admin doesn't declare that var, so the 32px fallback always won, and on mobile (where the WP admin bar is 46px) the sticky bar overlapped the back arrow by ~14px. New `@media (max-width: 782px)` rule sets `top: 46px` explicitly. Customer phone number is now KEPT visible on mobile in the call CTA (was `display: none`, hiding the number that owners use to verify it's the right customer); other CTAs still hide their labels on mobile.

= 2.1.16.0 =
* **Three owner-reported regression fixes.** No new features; closes the gaps in flows that were partially built but never wired through.
* **Progress bar wraps to a second row on the main SPA.** `applicableSteps()` returns 7 step ids (task_selection / photos / contact_details / otp_verify / address_details / assistant / booking) but `.handik-progress-dots` was hard-coded to a 6-column grid, so the 7th dot dropped to the next line. Switched the grid to `repeat(var(--handik-progress-step-count, 7), …)` and the renderer now writes the actual step count into the inline style — bar always lays out on a single row, and adding / removing a step in the future doesn't require touching this rule.
* **30-day verified-client cache no longer asks for the phone again on the next visit.** The cache (HMAC-signed token in localStorage, server-rehydrated via `/phone-verify/restore`) was already writing on OTP success and reading on boot — `state.phoneVerified` flipped to `true`, the profile was prefilled, and a comment in `contactMarkup()` even promised "Returning customers will auto-skip to address_details on success" — but no code performed the skip. The customer landed on `contact_details` and was prompted for the phone all over again, defeating the changelog promise. Three fixes:
  1. `applicableSteps()` now drops `contact_details` and `otp_verify` from the timeline when `phoneVerified` is true, so the progress bar reflects the customer's actual flow (5 steps instead of 7).
  2. `photos-next` action handler now jumps straight to `address_details` when the cache has restored the verified state — mirroring what `verifyPhoneOtp()` does after a fresh OTP.
  3. `init()` advances `state.step` past `contact_details` / `otp_verify` if the local draft happened to be parked on one of those steps when the cache restore succeeded (would otherwise leave the SPA on a step that no longer exists in the timeline).
  Restart still clears the cache as before, so the "log out / different customer on shared device" path is unaffected.
* **App Setup → Service area → Allowed ZIP codes finally works.** Two unrelated keys had drifted apart since 2.1.8.5: the admin textarea wrote to `service_area_zips`, but the only validator (`App_Controller::serviceable_zips()`) read from `serviceable_zips` — which the admin UI never populated. Result: the SPA's `isServiceableZip()` check always saw an empty list and accepted any ZIP, server-side accepted any ZIP, and the assistant was never told the address was out of area. Even the help text ("Booking app will only accept addresses whose ZIP appears here") was a lie. Three fixes:
  1. `App_Controller::serviceable_zips()` now reads `service_area_zips` first, with `serviceable_zips` as a legacy fallback for installs that may have populated the older orphan key. Same separator handling as the admin (whitespace / comma / semicolon all work).
  2. `class-assets.php` mirrors the same logic when bootstrapping `config.serviceableZips` for the SPA, so the client-side "We don't currently provide service to this ZIP code" hint at the address step finally fires.
  3. New server-side gate in `App_Controller::save_draft()` returns a 422 with a friendly message when the admin has populated a non-empty list and the submitted ZIP isn't on it. Defense-in-depth — a stale or hostile client can no longer bypass the check by skipping the SPA's hint. Empty list = accept any (unchanged).

= 2.1.15.1 =
* **Hotfix — Sprint 7/8 independent audit findings.** No new features; only hardens what 2.1.14.0 + 2.1.15.0 introduced.
* **P0 — Cal.com credentials added to the capability-strip list.** The Sprint 8 capability split moved API-secret writes behind `handik_manage_integrations`, but the strip list omitted `cal_api_key`, `cal_api_base`, `cal_api_version`, `cal_api_timezone`. Those settings live on App Setup → Cal.com (booking-side), not the Integrations tab, so a `MANAGE_BOOKINGS`-only user could craft a settings POST that rotated the Cal.com API key. Strip list now covers them; settings save still falls through for everything else.
* **P1 — Cron fallback no longer drops events with no listeners.** The Sprint 8 heartbeat unschedule-then-dispatch order silently lost an event if a service hadn't yet registered its listener (race against `wp_loaded:99`). New order: dispatch first, then unschedule only when `has_action()` confirms a listener consumed it (or threw). A truly orphaned event stays queued and retries on the next heartbeat.
* **P1 — Modal focus trap: nested-dialog stack.** Each `trapModalFocus()` call now pushes onto a module-scoped stack; only the topmost trap responds to Tab. Older traps stay registered (so they can still release their cleanup) but don't double-handle Tab, so opening modal B from modal A no longer makes focus jump unpredictably between dialogs. Release also now validates `document.contains(previouslyFocused)` before refocusing — prevents a silent focus drop when the trigger button was inside a list that re-rendered while the dialog was open.
* **P2 — Migration lock is now atomic.** Sprint 7 had a check-then-set sequence in `Migrations::acquire_lock()` that two near-simultaneous boots could both pass, defeating the lock. Switched to `add_option()` which the wp_options `option_name` UNIQUE index enforces at the DB level — exactly one caller wins the insert. The 60-second stale-lock recovery branch is preserved for crashed/timed-out callers.
* **P2 — `ob_implicit_flush()` parameter type.** PHP 8.0+ deprecated the int form. CSV streamer now passes `true`. Also dropped the corresponding PHPCompatibility ignore comment.
* **PHPStan baseline cleanup.** Removed redundant `is_array($result)` defensive checks in `admin_migrations_run` (the migration runner returns a guaranteed array shape); removed the now-unused `Cannot access offset .* on mixed` ignore pattern from `phpstan.neon.dist`. Touched files are now PHPStan-clean.

= 2.1.15.0 =
* **Sprint 8 — Cross-cutting polish (4 of the 5 P2 items from the v2.1.11.1 QA report; dark mode skipped per owner).** All admin / ops surface; no public flow changes.
* **Date format unification.** Five different date renderings across admin pages (booking cards `D, M j · g:i A`, default `l, F j, Y g:i A`, People list raw `human_time_diff`, Direct/Project lists raw MySQL DATETIME, raw-tables/log time as-stored) collapsed into two shared helpers: `Handik_Booking_App_Admin_Helpers::format_short($datetime, $assume_utc=true)` → `Mon, Jan 15 · 2:00 PM` and `format_long(...)` → `Monday, January 15, 2024 · 2:00 PM ET`. Both convert to Eastern Time using the existing `utc_to_eastern` helper. Logs pass `$assume_utc=false` because `Logger::log()` writes `current_time('mysql')` (site-local). Drift sites fixed: bookings `Updated` cell, People row `last_seen_text`, request focus list `updated_at` (×2), Additional Forms direct/project list `created_at` (×2), Logs card `time`.
* **Status pill differentiation.** `booked` / `confirmed` / `completed` all collapsed to one shade of green — at-a-glance, a future visit looked the same as a finished one. New mapping: `booked` → blue (info), `confirmed` → green (success), `completed` → deep teal (`pill--done`). All other tones (`danger`, `warning`, `muted`, `info`, `neutral`) unchanged so existing CSS keeps working. Also bumped `--muted` text from `#64748b` on `#e2e8f0` (3.7:1, fails WCAG AA per the audit) to `#475569` (≥ 4.5:1).
* **Capability split.** Single `manage_options` gate replaced with two custom caps: `handik_manage_bookings` (Dashboard, Bookings, People, Setup, System, Logs, Additional Forms — every day-to-day operations surface) and `handik_manage_integrations` (the Integrations tab on the Operations page only — OpenAI / Twilio / GitHub / Google Maps API keys + Cal.com webhook secret). New `Handik_Booking_App_Capabilities` class registers both caps on `administrator` at activation and grants both transparently to anyone holding `manage_options` via a `user_has_cap` filter, so existing site admins keep working without any data migration. The Integrations tab renders an "insufficient permissions" notice instead of the form when the wider cap is missing; the shared settings POST handler strips integration-credential keys (`openai_api_key`, `twilio_auth_token`, `github_access_token`, etc.) from the payload when the submitter only holds `handik_manage_bookings`. Lets an owner hand the day-to-day off to an editor without exposing rotating tokens.
* **Cron fallback heartbeat.** Sites with `DISABLE_WP_CRON` (common nginx + page-cache config) lose the auto-trigger that fires `wp-cron.php` on every page load. Single events queued via `wp_schedule_single_event` (photo-analysis refresh, abandoned-draft GC, etc.) silently never fire on those installs. New `Handik_Booking_App_Cron_Fallback` class hooks `wp_loaded`, walks `_get_cron_array()` once per minute (transient-throttled), and inline-fires any overdue **handik_*** events — scoped tightly so we don't second-guess unrelated plugins that opted into manual cron. Heartbeat is dormant (no-op) when WP cron is enabled; it skips AJAX / cron / CLI requests entirely. When PHP-FPM's `fastcgi_finish_request()` is available it ships the page response first and runs the events afterwards so the user's browse isn't slowed down. System info > Plugin now shows whether the fallback is active.

= 2.1.14.0 =
* **Sprint 7 — Admin performance + accessibility (5 items from the v2.1.11.1 QA report).** All cross-cutting wins; no public-flow regressions, no DB / REST contract changes.
* **N+1 admin queries → bulk `get_many()`.** Bookings list (cards + table), Dashboard "Next 5 visits" + today/tomorrow/week previews, and People list focus chips were each fanning out 1-3 single-row queries per row inside a loop. New helpers — `Job_Requests_Service::get_many()`, `Bookings_Service::get_many()`, `Bookings_Service::find_latest_for_requests()` — let each admin page bulk-load decorations in one IN(...) query. Examples: a 100-row bookings page dropped from ~300 round trips to 4. Dashboard cache-miss path: 8 single-row `bookings->get()` calls collapsed into one. Person detail with 30 historical requests: 30 `find_latest_for_request` calls collapsed into one.
* **CSV export streams instead of buffering the whole table.** `/admin/export/<table>` (System info → "Export tables to CSV") was `SELECT * FROM <table>` with no LIMIT, then loaded the full result set into PHP memory. Fine for `contacts` (1k rows), fatal for `messages` (100k rows = OOM at the PHP memory_limit). The export is now keyset-paginated by `id` in 1000-row batches, written to `php://output` and `flush()`-ed each batch. Peak memory bounded by `$batch_size`, not the table size.
* **Modal focus trap (WCAG 2.4.3).** Tab and Shift+Tab now cycle within the dialog instead of leaking to the underlying admin / SPA DOM. Added a shared `trapModalFocus()` helper to `booking-app-admin.js`, `booking-app.js`, and `booking-forms.js` (kept inline because there's no shared module loader yet). Wired into the admin `openModal` confirm dialog, the admin "Edit address" modal, and the public-flow "Start over?" restart-confirm dialog (main + Additional Forms). Trap also restores focus to the previously focused element on close so keyboard users don't snap to the top of the page.
* **Catalog drag-handle keyboard reorder (WCAG 2.1.1).** App Setup → Service catalog used SortableJS only — keyboard users had no way to change order. Handles are now real `<button>` elements with `aria-label` and an arrow-key reorder fallback (Up/Down by one position, Home/End to jump). Move events feed the same `scheduleSave` pipeline SortableJS uses, so the auto-save status pill reports correctly. Polite-live-region announces "Moved to position X of N" after each step. Visible focus ring added.
* **Migration runner accuracy.** Three QA-found bugs in `Handik_Booking_App_Migrations::migrate()`:
  1. `LAST_RUN_OPTION` only got written when at least one migration class actually executed — calls that hit a no-op left the System info "Last migration ran" timestamp stuck at whatever a prior version recorded. Now also writes `LAST_ATTEMPT_OPTION` on every invocation.
  2. The version pointer `OPTION_NAME` was bumped before `up()` ran. A throwing migration left the schema partially migrated AND the pointer past the failed step, so the next attempt skipped it. Now: try/catch around each step, version pointer + `LAST_RUN_OPTION` only updated on success, `LAST_ERROR_OPTION` set on failure.
  3. Two parallel boots on a fresh upgrade both read the same starting version, both ran the same migration, and the second got "Duplicate column" / "Table already exists". A 60-second `LOCK_OPTION` transient now serialises runs.
  The admin "Run pending migrations" REST response now reports `ran[]`, `skipped`, `error`, `no_changes` instead of always `success: true` — admins can distinguish "no migrations needed" from "migrations actually ran" from "a step failed".
* **Bug fix — Selected tasks & rates sheet on desktop.** Owner-reported: on desktop the sheet sat ~80px above the bottom edge AND drifted off-screen to the right. Two underlying issues: (a) base rule had `bottom: 80px + safe-area` to clear a docked footer that's actually `position: static` on desktop, so the offset was just dead space, and (b) `left: 50%` + `transform: translateX(-50%)` silently breaks when an Elementor section / theme wrapper has `transform: …` / `will-change: transform` / `filter: …` — that parent becomes the containing block for `position: fixed`, `50%` resolves against an offset rect, and the sheet ends up off-screen. Fix: anchor to viewport bottom (`bottom: env(safe-area-inset-bottom, 0)`) and use `left: 0; right: 0; margin-inline: auto` for centering — robust against parent transforms. The `is-bouncing` keyframe transforms still win during the bounce animation. Padding on the task list reduced from 168px → 96px to reclaim the space.

= 2.1.13.1 =
* **Hotfix — Twilio Verify OTP UX.** Three production bugs reported after 2.1.13.0:
  1. **Additional Forms — typed code disappeared on blur, Verify stayed disabled.** The `setFieldValue` helper only handled 2-part state paths (`contact.phone`, `address.address_full`); `otpCode` is a single-key path so the typed digits were silently dropped. The next blur re-render flushed the field back to the empty initial value. Fix: `setFieldValue` now writes single-key paths directly onto `state`.
  2. **Both forms — manual Verify button is gone.** The 6-digit code now verifies automatically the moment the customer types the last digit (or accepts the iOS one-time-code autofill chip). Visible-but-disabled Verify buttons were a dead-end UX — and removing them removes a whole class of "did I tap?" double-submit failures. Re-entry guard on `verifyPhoneOtp` blocks duplicate POSTs from the same input event (iOS autofill fires both `input` and `change` in the same tick).
  3. **Main form — premature "Welcome back" notification.** The legacy `/contacts/lookup` was firing on phone-field input/blur, BEFORE OTP verification, leaking returning-client status to anyone who guessed a phone number AND showing the welcome toast in the wrong order. The lookup is removed from the contact-details handlers; `/phone-verify/check` already returns the same profile after Twilio approves, and `verifyPhoneOtp` owns the welcome toast on the post-OTP path.
  4. **Main form — Twilio "VerificationCheck not found" 404.** Caused by double-firing `/phone-verify/check` when the customer double-tapped Verify (or the click and an iOS autofill input event landed in the same tick): the first call approved the verification and Twilio retired it, the second hit returned 404. The new re-entry guard plus the auto-advance flow (one input → one POST) close this off. On error the OTP buffer is cleared so the customer can retype without manually clearing the field.
* OTP intro copy updated everywhere: "Enter the 6-digit code we just sent to %s. We will verify it automatically."
* No DB / schema changes. No REST contract changes. Drop-in over 2.1.13.0.

= 2.1.13.0 =
* **Sprint 6 — phone-first OTP comes to the main `[handik_booking_app]` form.** The Additional Forms cohort has been on the new flow since 2.1.12.0; this release completes the migration.
* **What customers see**:
  - **Contact details step** is now phone-only. One field, one CTA ("Send code"). Twilio Verify SMS goes out.
  - **New OTP step** between contact and address: 6-digit code field with `inputmode="numeric"` + `autocomplete="one-time-code"` (so iOS Mail / Android SMS-autofill can drop the code in), plus a 30-second-locked Resend button and a "Use a different number" link to bounce back to the phone screen.
  - **Address step is now the combined "details" screen**:
    - **Returning customer** (verified profile matched a CRM contact): saved-address `<select>` + address + unit. Name and email are already on file.
    - **New customer**: full name + email + address + unit, all on one screen.
* **Verified-client cache (30 days)**. Same `handik_verified_client_v1` localStorage key as the Additional Forms — a customer who verified on either form is recognized on the other. On boot, `/phone-verify/restore` revalidates the HMAC-signed token and rehydrates the profile, so a returning visitor opens the app straight on the address screen with no OTP at all. "Start over" wipes both the draft and the verified-client token.
* **PII closure on the main form** matches Sprint 5: `/phone-verify/check` is the only path that returns saved profile data, and only AFTER Twilio confirms the customer owns the phone. The legacy `/contacts/lookup` round-trip is no longer the gate.
* **Placeholders** on every input across the contact + address screens (phone `+1 555 123 4567`, full name `Jane Smith`, email `you@example.com`, OTP `6-digit code`).
* **`inputmode` / `autocomplete` hints**: tel for phone, numeric+one-time-code for OTP, email for email, name for full name. Mobile keyboards get the right layout on first focus.
* **Compatibility**: the verified-client token format is identical between the main form and the Additional Forms (single `Auth_Service::build_verified_token` issuer, single REST endpoint set under `handik-booking-app/v1/phone-verify/*`). Sites can run both forms with one Twilio Verify configuration.

DB schema unchanged. The verified-client cookie reuses the existing `handik_booking_app_client` HMAC cookie from the email magic-link flow.

= 2.1.12.0 =
* **Sprint 5 — phone-first contact flow.** Re-introduces Twilio Verify SMS as the primary identity step for the Additional Booking Forms (the main `[handik_booking_app]` form follows in 2.1.13.0). The new journey is: phone → SMS code → branch.
  - **New customer**: name + email + address on a single screen.
  - **Returning customer**: saved-address picker + address on a single screen, name and email already prefilled from CRM.
  - **30-day verified-client cache** in `localStorage` (HMAC-signed token, validated server-side per request) — a customer who verified yesterday opens the form straight on the address screen with no OTP at all. Cleared on "Start over" + on cookie/cache expiry.
* **PII fix on `/contacts/lookup` path.** The new `/phone-verify/check` endpoint only returns the customer's profile AFTER Twilio confirms the phone is in their possession. The legacy `/contacts/lookup` lookup is no longer the gate to the CRM — closing the audit-flagged P0 where any phone could fish out names/emails.
* **Soft phone input.** Removed the per-keystroke reformat — typing produces no mutated value, no caret jumps, no "+1 1 1" artifacts. Display is normalized once on blur (only when the value parses to a 10-digit US number); the API still receives the canonical E.164 form. Owner-reported regression closed.
* **Selected tasks & rates sheet now sits ABOVE the footer.** The desktop sheet was `position: sticky` with `z-index: 35` (footer is `40`), so the Continue button visibly covered the rate summary on long flows. It's now `position: fixed; z-index: 50; bottom: 80px` on every viewport, centered up to 980px wide, with extra bottom padding on the task-selection step body so the sheet doesn't overlap the last task chips. Mobile rules unchanged.
* **Placeholders on every customer-facing input.** Added `placeholder=""` for phone (`+1 555 123 4567`), full name (`Jane Smith`), email (`you@example.com`), unit (`Apt 3B`), and the OTP code (`6-digit code`). Address still uses the existing "Start typing the address of the job" placeholder.
* **Address autocomplete: prefill-vs-typed collision.** Browser autofill / Google Places suggestion fights are mostly resolved already; this release also tracks whether the customer has actually typed since the Places binding completed (a `__handikUserTyped` flag on the input). When `address.address_full` changes from autofill / pre-population, we no longer invalidate the Places verification — the next render keeps the customer's verified address valid. Only an actual keypress flips it back to "needs to be re-picked."
* **REST surface.** Four new public endpoints under `handik-booking-app/v1`:
  - `POST /phone-verify/start` — start a Twilio Verify (rate-limited per phone).
  - `POST /phone-verify/check` — submit OTP, get `{is_new_client, contact_id, profile?, verified_token, verified_phone}`.
  - `POST /phone-verify/restore` — revalidate a stored token + return profile.
  - `POST /phone-verify/bind-contact` — attach a contact_id to a new-client token after submit creates the row.
* **Architecture note.** The main `[handik_booking_app]` form keeps the existing contact step UNCHANGED in 2.1.12.0. The Twilio Verify rewrite for it lands in 2.1.13.0 once the Additional Forms cohort confirms the new flow is solid. The server endpoints in this release are designed to serve both forms.

DB schema unchanged. The verified-client cookie reuses the existing `handik_booking_app_client` HMAC cookie from the email magic-link flow.

= 2.1.11.1 =
* **Sprint 4 — admin polish + operational hygiene for Additional Forms.** Closes the P3 audit findings.
* **Project schedule admin actions.** The detail page now exposes a copy-friendly **Public link** field (the unguessable per-schedule token URL — useful for resending to a returning customer) and a per-day **Cancel** button that calls Cal.com's cancel API and marks the local row CANCELLED. Nonce-protected + `manage_options`-gated.
* **Direct submissions list shows the customer's address.** Earlier admins saw client/phone/preset but had to click into the contact to find the address. Now batch-rendered alongside.
* **N+1 contact lookups fixed.** Both the Direct and Project list pages used to call `Contacts_Service::get()` once per row (up to 100 round-trips). New `Contacts_Service::get_many()` and `Addresses_Service::get_many()` batch these into a single query each.
* **Mobile admin bottom-nav now includes Additional Forms.** It was reachable only via the side menu before — now there's a "Forms" tab in the bottom strip on mobile.
* **Daily cleanup cron for abandoned schedules.** A new `handik_booking_app_form_gc_abandoned` cron deletes `project_scheduling_requests` rows that have been in DRAFT / SELECTING for more than 7 days (plus their `project_work_days` children). CONFIRMED, PARTIAL_FAILED, and CREATING are never touched — the GC is conservative on purpose.
* **Cloudflare-aware client IP everywhere.** Both the IP packed into `direct_booking_requests.client_ip` / `project_scheduling_requests.client_ip` and the per-IP rate-limit bucket now check `Cf-Connecting-Ip` → `X-Forwarded-For` → `REMOTE_ADDR`. Sites behind CloudFlare no longer rate-limit on a single proxy IP.
* **Numeric validation on `cal_event_type_id`.** Pasting a slug or a typo into the preset edit form used to silently 400 every `/v2/slots` request later. Now the field is digit-stripped on save.
* **Raw tables (debug) view + CSV export now include the four Additional Forms tables.** `form_presets`, `direct_booking_requests`, `project_scheduling_requests`, `project_work_days` are inspectable from System info → Raw tables, and have download buttons under "Export tables to CSV".
* **Operator name no longer hardcoded in customer copy.** New setting `operator_first_name` (default `Alex`). All "Alex will follow up" / "Alex will be in touch" / "contact Alex directly" strings now substitute this. The localizable templates carry `%s` so translators can place the name anywhere in the sentence.
* **Auto-flush rewrite rules on version bump.** Plugins updated via FTP / WP-CLI never fire the activation hook, so `/booking/{slug}` could 404 until the admin saved Permalinks. The router now records the last-flushed version and re-flushes once when it sees a new version on `init`.
* **Code de-duplication.** `build_encoded_url` and `client_ip_packed` were copy-pasted across the three forms services with subtle drift. Pulled into `Handik_Booking_App_Forms_Helpers` so all three flows agree on the encoding rules and IP-detection chain.

DB schema unchanged.

= 2.1.11.0 =
* **Sprint 3 — UX parity + accessibility for Additional Booking Forms.** Closes the customer-facing P2 findings from the v2.1.9.10 audit.
* **Local draft persistence (24h TTL, version-pinned).** Customer journeys are now resumable: refreshing the page or reopening the tab puts the customer right back where they left off, with name/phone/email/address and any selected project days intact. Stored under a per-preset namespace in `localStorage`, debounced 500ms on input, cleared automatically on success or "Start over". Plugin version is part of the envelope so a plugin upgrade invalidates stale drafts.
* **Real-time contact recognition.** As soon as the customer types a 10-digit phone number, the form silently hits `/contacts/lookup` and (on match) prefills name + email and shows the "Welcome back" toast — no more waiting until Continue. The lookup is per-phone cached so a second lookup on Continue doesn't fire.
* **Saved-address skeleton loader.** While the lookup is in flight the address step shows a small shimmering placeholder instead of the dropdown popping in abruptly after a returning customer's address-step render.
* **Smooth scroll-into-view on every step change.** Long pages (forms inside a tall hero/header) no longer require manual scrolling after each Continue. Same 80px header offset and rAF deferral as the main `[handik_booking_app]` form.
* **Success-step disclaimer variant.** After a confirmed booking the footer reads "All set. Alex will be in touch before the visit. · Book another visit" instead of the in-flight "Stuck? Start a new booking" prompt that was nag-shaped on the confirmation card.
* **Field errors clear when the customer fixes them.** Earlier the `is-invalid` styling and inline error span lingered after a blur on a different field on the same step; now any blur on the contact or address step re-renders so the error span disappears as soon as the value passes validation.
* **Locale-aware date/time formatting.** Hardcoded `'en-US'` arguments in `toLocaleDateString` / `toLocaleTimeString` removed. Now uses the browser's preferred locale (with fallback to `<html lang>`), so weekday/month names match the customer's language. Time zone still defaults to `America/New_York` per Cal.com setup.
* **Accessibility additions:**
  - `<input>` with an inline error now carries `aria-invalid="true"` + `aria-describedby` pointing at the error span. Screen readers pair the message with the field.
  - Email / tel / url inputs ship with `autocapitalize="off"` + `spellcheck="false"` to stop mobile Safari from autocaps-ing the first letter and underlining the address as misspelled.
  - The toast container now has `role="region"` + `aria-label="Notifications"` so AT can navigate to it as a landmark.

= 2.1.10.1 =
* **Sprint 2 — state-machine + Cal-integration bugs in Additional Forms.** Closes the P1 findings from the v2.1.9.10 audit.
* **Project schedule state machine clarified.** `confirm_schedule` now accepts only `SELECTED → CREATING` and returns explicit, action-specific errors for the other states (CREATING / PARTIAL_FAILED / ROLLED_BACK), so the customer or admin always knows what to do. Earlier the allow-list listed CREATING / PARTIAL_FAILED / ROLLED_BACK but the CAS lock only matched SELECTED, so retries from those states 409'd forever.
* **`save_selection` accepts ROLLED_BACK.** When the customer's first confirm hits a stale slot mid-flight and the rollback completes, they can now pick a fresh set of days inside the same form — no admin intervention needed. Previously they were stuck with a one-shot form.
* **Cal cancel calls now carry a `Cal-Idempotency-Key`.** Header value `handik-cancel-{uid}` makes the second cancel a 2xx instead of "already cancelled" 4xx — which previously tipped a clean rollback into `PARTIAL_FAILED` despite Cal having no booking left.
* **Cal embed query params trimmed before mounting.** The booking URL Cal.com hands back includes booking-page-only params (`overlayCalendar` / `month` / `date` / `slot` / `embed` / `embed_origin` / `layout`) that, when forwarded to the inline embed, made the calendar deep-link past the picker into a "no slots" view. Mirrors the main `[handik_booking_app]` form's identical blacklist.
* **`validateFullName` accepts a period.** Names like "John A. Smith" or "Jr." now pass — previously they were rejected and the customer was bounced from the contact step. Matches the main form's `[\p{L}\s'.-]` regex.
* **Address validation parity with main form.** Google Places suggestions now require `formatted_address` + line one + geometry **AND** `postal_code` + `administrative_area_level_1` (state). Otherwise downstream payloads reaching `/forms/direct/submit` and the project APIs were missing fields the CRM and Cal location string need.
* **Phone formatter preserves caret position.** Editing the middle of a phone number no longer jumps the cursor to the end after every keystroke. Same digits-before-caret + walk-forward algorithm the main form uses.
* **"Start a new booking" now confirms before wiping state.** A misclick on the Stuck disclaimer used to nuke a half-completed Project Work Days selection. Now opens a small modal with `Keep my booking` / `Start over` buttons. Mirrors the main form's restart modal.
* **`seed_defaults` race-safe.** Two concurrent first-page-load requests no longer collide on the `preset_slug` UNIQUE index and leave the table partially seeded. Each row pre-flights with a SELECT; the UNIQUE index still backs us at the storage layer.
* **Edit button hidden when preset is an in-memory default.** Before the first plugin activation seeds the `form_presets` table, the admin list rendered Edit links to `?preset_id=0` that resolved to "Preset not found." Now those rows show a `default` chip with a tooltip asking the admin to activate the plugin / run pending migrations.

= 2.1.10.0 =
* **Security sprint #1 — Additional Booking Forms.** Closes the P0 findings from the v2.1.9.10 audit. Public REST endpoints under `handik-booking-app/v1/forms/*` are now hardened against the abuse paths the audit flagged.
* **Nonce-protected POSTs.** Every write endpoint (`/forms/direct/submit`, `/forms/direct/{id}/capture`, `/forms/project/open`, `/forms/project/{id}/select`, `/forms/project/{id}/confirm`) now requires the `wp_rest` nonce. Off-site origins can no longer fire-and-forget into the CRM.
* **Sliding-window rate limit.** Per-IP, per-bucket transient-backed throttle: 30 submits/min and 60 reads/min by default. Filters: `apply_filters( 'handik_booking_app_form_rate_limit', $limit, $bucket )`. IP detection prefers `Cf-Connecting-Ip` → `X-Forwarded-For` → `REMOTE_ADDR` so CloudFlare-routed traffic doesn't all share one bucket.
* **IDOR fix on `/forms/direct/{id}/capture`.** New `capture_token` column on `direct_booking_requests` (migration 1.4.1). The submit handler issues a 32-char `wp_generate_password` token and returns it once; `/capture` rejects requests whose token doesn't match (`hash_equals`). Anonymous parties can no longer iterate auto-increment IDs and overwrite `cal_booking_id` / `status`.
* **State-machine precondition on `capture_booking`.** Captures are now only accepted from `READY` or `OPENED` states. `BOOKED` is an idempotent success (Cal embed sometimes double-fires `bookingSuccessful`); `CANCELLED` returns 409 instead of being silently re-opened. The SPA also debounces locally so the second event never leaves the browser.
* **Webhook `map_status` whitelist.** The Cal.com webhook used to default unknown events (`meeting_started`, `payment_initiated`, etc.) to `booked`, which could silently flip a cancelled booking back open. Status mapping is now whitelist-only — known events resolve to one of `booked` / `rescheduled` / `cancelled`; everything else acknowledges (so Cal stops retrying) without mutating state.
* **Webhook project-routing guard.** A Cal webhook flagged as `project_work_days_form` must now carry both `metadata.handik_project_schedule_id` AND a booking UID. Missing or malformed metadata short-circuits with a logged warning instead of falling through to the AI-flow contact-fallback matcher (which could attribute a project booking to the wrong job request).
* **`<title>` no longer leaks disabled presets.** `/booking/{slug}` for a disabled preset still shows the friendly "not available" body, but the document title is now the generic "Book a visit" instead of the offering name — no more advertising services that the admin has explicitly turned off.
* **Defensive `addresses->sync()` return checks.** Both `direct_booking->submit()` and `project_schedule->open_schedule()` now bail with a 500 error when the addresses service returns 0 (validation-level failure), instead of persisting a row pointing at address #0.

DB migration: 1.4.0 → **1.4.1** (one ALTER TABLE adding `capture_token` to `direct_booking_requests`). The migration is idempotent — runs once on plugin update.

= 2.1.9.10 =
* **Direct Visit progress dots count fixed.** Direct flows (Standard / Extended / Large Visit) now show 3 dots in the progress bar — Contact → Address → Pick a time — instead of 4. The terminal `success` step (the confirmation card) is no longer counted as a separate dot, matching the main `[handik_booking_app]` form which keeps `booking` as the last dot and never adds a separate success entry. Project Work Days flows correctly show 4 dots (Contact → Address → Choose days → Review).
* **Pick a time has no Back/Continue.** The Cal.com embed step is the final step of the direct flow, same as the main form's `booking` step, so it no longer renders a footer with a Back button. The customer either books inside the iframe or uses the "Stuck? / Open the booking page directly" disclaimer.
* **Project Work Days "Confirm selected days" button is no longer stuck disabled.** After save_selection completed the busy flag was reset to false but the Confirm button on the next screen kept its `aria-disabled="true"` because earlier code mutated DOM attributes directly instead of re-rendering. The busy() helper now updates `state.busy` and triggers a single re-render — render() recomputes `continueMuted` from busy + validation, so the button is always in sync.
* **Browser back / forward buttons now navigate between steps.** Wired History API the same way the main form does it: `replaceState` on initial mount, `pushState` on every step transition (skipping the navigation-from-popstate case to avoid loops), and a single `popstate` listener that scopes by `instanceId` so other apps on the page can't trigger us. Hitting the browser back button rewinds one step at a time instead of leaving the page.

= 2.1.9.9 =
* **Fixed: Project Work Days slot loader returned 404 from Cal.com.** The Cal.com Platform API v2 versions each endpoint independently — `GET /v2/slots` only exists on `cal-api-version: 2024-09-04`, while `POST /v2/bookings` and `POST /v2/bookings/{uid}/cancel` still expect `cal-api-version: 2024-08-13`. The plugin was sending `2024-08-13` for everything, so the slots fetch hit `Cannot GET /v2/slots` from Cal.com's NestJS router. Each public method on `Handik_Booking_App_Cal_Api_Service` now passes its own pinned version (`SLOTS_API_VERSION` / `BOOKINGS_API_VERSION` constants), and the `request()` helper accepts a per-call `version` override. The `cal_api_version` admin setting is now a global fallback only — used when no per-endpoint pin applies.
* **Default cal_api_version setting bumped to `2024-09-04`** for fresh installs.

= 2.1.9.8 =
* **Fixed: "Cal is not defined" white screen on direct visit forms.** The 2.1.9.5 → 2.1.9.7 builds installed a minimal Cal.com embed queue stub that worked for the main `[handik_booking_app]` form but not for the new Additional Forms because the new SPA called `Cal('init', namespace, …)` BEFORE the embed.js script finished loading. When the script then loaded, it sometimes hit the queue stub in an inconsistent state and threw `Cal is not defined. This shouldn't happen` from inside embed.js. Replaced the loader with the canonical Cal.com bootstrap pattern (the same one the main form has been shipping for months): a queue function that lazily appends the embed script on first invocation, sets up `cal.ns` namespaces, intercepts `init` to allocate per-namespace queues, and is polled with a 5-second timeout to confirm it's installed. Calls made before embed.js finishes loading are queued and drained automatically when the script runs.
* **Fixed: progress dots left-aligned with empty cells on the right.** Main form uses `grid-template-columns: repeat(6, 1fr)` to fit its 6 steps. Additional Forms have 4 (direct) or 5 (project) steps so two cells stayed empty, leaving the dots flush-left within the centered band. The Additional Forms SPA now overrides the column count inline (`grid-template-columns: repeat(<step-count>, minmax(0, 1fr))`) so the dots fill the centered `.handik-global-progress` band evenly.
* **Idempotent Cal mount per DOM node.** Replaced the previous `calMounted` instance flag (which made re-entry to the cal step after a `Back` skip the mount on the new DOM container) with a `data-handik-cal-mounted="1"` attribute on the embed container itself. A re-render WITHIN the cal step is a no-op, but a step-change-and-return rebuilds the container so the embed mounts cleanly.

= 2.1.9.7 =
* **Full visual parity audit + rewrite of the public Additional Forms SPA.** A line-by-line review of `assets/booking-forms.js` against `assets/booking-app.js` surfaced six structural gaps that were keeping the new forms from looking and behaving like the main `[handik_booking_app]` flow. Fixed all of them in one pass:
  1. **Saved addresses are now a `<select>` dropdown.** Previously rendered as a stack of clickable cards — now matches the main form's `<select id="handik-form-saved-address">` with a `Choose saved address` placeholder + one `<option>` per saved address. On change, the chosen address fills the form and focus jumps to the unit field.
  2. **Step-specific h2 headers.** Each step now shows its own heading: "Contact details", "Address details", "Pick a time", "Choose project work days", "Review your selected days", "You're all set". Previously every step showed the preset title.
  3. **Progress dots.** Below every screen we now render `<ol class="handik-progress-dots">` filled to the current step (4 dots for direct visit, 5 for project work days), reusing the main form's `.handik-progress-dots` styling.
  4. **"Stuck?" footer disclaimer.** Same `Stuck? Start a new booking · Open the booking page directly` link the main form ships, with the same `data-action="restart"` behavior (resets state and returns to the contact step).
  5. **Address-validation parity.** Continue is now muted on the Address step until a Google-Places-verified address is chosen (`address.is_valid === true`). Inline error spans match the main form's `.handik-field__error` class. Typing into the address input after a previous selection invalidates `is_valid` so the customer must re-pick from the suggestions.
  6. **Inputs bound via `data-model="…"`** instead of `name="…"`, so the address inputs can carry the `name="handik_form_location_query"` / `name="handik_form_unit_detail"` autofill-suppression names the main form uses without breaking state binding.
* **Documentation pass.** Top-of-file JSDoc now restates the visual contract; every new method carries a short inline comment explaining intent. The class is organized into clearly labeled sections (Lifecycle / Render / Step renderers / Field helpers / Events / Validation / Saved addresses / Network / Cal embed / Google Maps / Toasts / Pure helpers / Bootstrap).

= 2.1.9.6 =
* **Fixed: Google Maps suggestions were unclickable.** Two root causes — the page's stacking context (often Elementor) was rendering above Google's `.pac-container`, and the browser's native address-fill heuristic was competing with the Places dropdown for input focus. Fix raises `.pac-container { z-index: 2147483647 !important; pointer-events: auto !important }` and applies the same autofill-suppression attributes (`autocomplete="new-password"`, `data-lpignore`, `data-1p-ignore`, renamed input field) the main `[handik_booking_app]` form uses.
* **Fixed: saved addresses for returning clients.** When the customer types a phone number that matches an existing CRM contact, the address step now shows that contact's saved addresses as one-click cards above the address input. Click → fills address + unit, focuses the unit field. Lookup is cached per-phone so re-typing the same number doesn't spam the endpoint. Soft-fails to a fresh-customer flow on network error.
* **Fixed: removed `<h2>` focus-on-step-change.** The new forms inherited an obsolete a11y pattern that the main form had already rolled back (it was dismissing mobile keyboards and confusing screen magnifiers on every transition).
* **Fixed: toast notifications now reliably animate in.** A subtle browser-batching issue made the entrance transition occasionally drop frames so toasts looked invisible. The notification builder now forces a synchronous reflow (`void item.offsetWidth`) after appending the element, before adding `is-visible`.
* **New microcopy:** "Welcome back — we found your saved addresses." toast on returning-client match, "Use a saved address" header above the cards.

= 2.1.9.5 =
* **Visual parity with the main booking app.** The Additional Booking Forms (`[handik_booking_form]` shortcode and `/booking/{slug}` routes) now reuse the entire `booking-app.css` design system. Same colors, typography, sticky Back/Continue footer, toast notifications, loading bar, and Cal.com embed wrapper as the main `[handik_booking_app]` form — they just skip the AI assistant, photos, and task selection.
* **Cal.com embed instead of raw iframe.** Direct visit forms now mount the Cal.com embed via `embed.js` (the same loader the main form uses). The customer sees the same calendar UI, with a 15-second fallback to "open in a new tab" when the script fails to load.
* **Google Maps Places autocomplete.** Address field on every additional form now suggests addresses through the same Google Maps key configured in App Setup → Integrations. Falls back to manual entry when the key is empty or the script blocks.
* **Appearance tokens forwarded.** All `--handik-*` CSS variables from App Setup → Appearance are inlined onto the form root, so colors, font, button styles, and radius track the rest of the app.
* **Toast notifications.** Inline error toasts render in the same bottom-right stack as the main app (`.handik-booking-app__notifications` / `.handik-toast`).
* **Preset editor in admin.** Handik Booking → Additional Forms → Presets now shows an Edit button per row. The form lets you set: title, enabled flag, duration / required days / work-day duration, Cal.com event type id or slug (for project work days), Cal.com URL override (for direct visits), and admin notes. No more MySQL needed to wire a preset to Cal.com.
* **Code organization.** `booking-forms.css` now holds only the picker/review/success additions; everything else inherits from `booking-app.css` via a `wp_register_style` dependency.
* **Documentation pass.** All new files carry full PHPDoc + JSDoc explaining responsibilities, contracts, and gotchas.

= 2.1.9.2 =
* Fixed a public Additional Forms JavaScript error: `this.render is not a function`.
* Keeps the Additional Booking Forms module on DB schema `1.4.0`; no new migration beyond 1.4.0 is required.

= 2.1.9.1 =
* **NEW MODULE — Additional Booking Forms.** Adds two new lightweight public booking flows that share the existing CRM (contacts/addresses/logs) but bypass the AI assistant. Embed via shortcode `[handik_booking_form preset="standard-visit-60"]` or auto-generated route `/booking/{preset_slug}`.
* **Direct Visit forms (8 presets).** Standard / Extended / Large visits with locked durations (60/120/180/240/300/360/420/480 minutes). Flow: Contact → Address → Cal.com iframe with the duration pre-selected. Uses RFC-3986-encoded URL parameters with `attendeePhoneNumber=%2B…`, JSON `location`, and metadata so Cal.com webhooks identify the source.
* **Project Work Days forms (5 presets).** For approved larger-scale projects of 2–6 days. Flow: Contact → Address → Multi-day picker → Review → Confirm. The plugin acts as the orchestrator: it loads slots from the Cal.com v2 API, lets the customer pick exactly N days, re-checks availability on confirm, then creates N separate Cal.com bookings server-side. Idempotency-keyed POSTs prevent double-bookings. If any day fails after others succeed, the plugin automatically rolls back the created bookings via the cancel API and tells the customer to pick replacements.
* **Webhook routing.** `cal-webhook` now dispatches by `metadata.handik_booking_source` (after HMAC verification): `direct_booking_form` → `handik_direct_booking_requests` row, `project_work_days_form` → matching `handik_project_work_days` row. Main AI flow is unchanged.
* **Schema (migration 1.4.0).** Adds 4 tables: `form_presets` (13 default presets seeded on first run), `direct_booking_requests`, `project_scheduling_requests` (with unguessable `public_token`), `project_work_days`.
* **Admin → Additional Forms.** Three tabs — Presets list (with copy-friendly shortcode + public URL), Direct Submissions, Project Schedules with day-level detail.
* **Settings → App Setup → Cal.com.** New "Cal.com API (Project Work Days)" section: API key (Bearer), base URL (default `https://api.cal.com/v2`), version header (default `2024-08-13`), default timezone (default `America/New_York`). Override via `HANDIK_BOOKING_APP_CAL_API_KEY` constant in `wp-config.php` for stricter security.
* **Architecture.** New `includes/forms/` namespace keeps the module isolated: `class-cal-api-service.php`, `class-booking-presets-service.php`, `class-direct-booking-service.php`, `class-project-schedule-service.php`, `class-forms-rest-api.php`, `class-forms-router.php`. Plus admin renderer `includes/admin/class-admin-additional-forms.php`. Frontend is a single `assets/booking-forms.js` (mobile-first, vanilla JS, no build step) + `assets/booking-forms.css`.

= 2.1.9.0 =
* **HOTFIX — infinite "Loading…" on Virtual assistant.** The 2.1.8.9 refactor accidentally let a generic `.handik-booking-app__loading-overlay { display: grid }` rule (further down in the stylesheet) win the cascade against the new `.handik-booking-app__loading-overlay--assistant { display: none }` rule, because both selectors had the same specificity. Result: the overlay was permanently visible and `setAssistantPreparingState(false)` had no effect. The fix raises specificity (`.handik-booking-app__loading-overlay.handik-booking-app__loading-overlay--assistant`) so the toggle works correctly.
* **HOTFIX — bridge prewarm regression.** The Sprint-1 prewarm helper used to set `record.session = options.prewarmedSession` immediately, which made `markChatActive()` think the session was already ready and skip the recovery `emitSessionReady('timeout')`. The bridge now only seeds `cachedSession` (used by `getClientSecret`) and lets the real session land in `record.session` when the API resolves.
* **HOTFIX — safety timer.** If the ChatKit element silently fails to report ready within 14 seconds, the assistant overlay is force-dismissed and the existing Plan-B banner is surfaced. The user is never stranded on a spinner.
* **HOTFIX — English-only public copy.** The assistant overlay, the bridge `loadingTitle`, and the "Book a time / Loading…" busy-button label no longer pull from the `ui_loading_*` settings. They use hardcoded English strings so a stale Russian saved value cannot leak into the public app. Owner-customizable copy (assistantContinue, assistantThinking, stuck banner) still works via admin settings.

= 2.1.8.9 =
* **Task screen reorder.** "Free Consultation" (rebrand of "Larger-Scale Work") is now the third card after "Choose Specific Tasks". Description is now "A free on-site visit to assess larger, multi-step, or unclear work before any quote or scheduling." with a "Free" price badge.
* **Assistant loading flicker fixed.** The "Loading virtual assistant…" overlay is now part of the rendered markup from the moment the user enters the assistant step — visibility is toggled via a CSS class. Removes the race condition where the overlay sometimes failed to appear and the user saw a blank panel for a few seconds.
* **"Thinking…" indicator on every reply.** The typing indicator now shows three bouncing dots plus a "Thinking…" label, as a `role="status"` live region for screen readers. Visible after every user message, hidden as soon as the assistant produces output. Label is admin-editable.
* **Plan B for stuck assistant.** If 30 seconds pass after a message without an assistant reply (or if the bridge fails to mount entirely), a soft warning banner appears with a direct "Open the booking page directly →" CTA pointing at the configured Cal.com fallback URL. Admin-editable copy. Click is logged so admins can see how often Plan B is used.
* **Bigger tap targets on mobile.** Task chips (`.handik-task`) now use 12px×14px padding, ≥44px min-height, and 0.92rem font on mobile (was 8px×10px / 0.78rem) so they're easier to thumb-press. Choice cards have a min-height floor too.
* **`prefers-reduced-motion` support.** Typing dots, stuck-banner reveal, and tap scale honor the OS reduce-motion preference.
* **Admin Setup → Booking flow → Step 5: Assistant.** Three new editable strings ("Thinking…" label, Plan-B title, body, and CTA) so the owner can tune copy without redeploying.

= 2.1.8.8 =
* Kept the final Cal.com booking URL internal to the app after `save_assistant_routing_result`, so ChatKit cannot surface a redundant booking link inside the assistant reply.
* The existing Book a time button remains the only customer-facing booking action from the assistant step.

= 2.1.8.7 =
* **A1 — Non-blocking photo analysis on save_assistant_routing_result.** `save_assistant_result` no longer waits up to 45 seconds on a fresh OpenAI Vision call; it uses the cached analysis and schedules a single async refresh via `wp_schedule_single_event` when the cache is empty. -3…-15 sec on cold-cache turns.
* **A3 — Prewarmed ChatKit session is actually used.** The session payload that booking-app.js fetches on the Address details step is now stashed and handed to the ChatKit bridge through a new `prewarmedSession` mount option, so the very first `getClientSecret()` returns synchronously instead of doing another create-session round-trip. -600…-1500 ms on first mount.
* **B1 — verify_draft_token memoization.** `wp_check_password` is intentionally slow (50–200 ms). One assistant turn hits 6+ REST endpoints that all verify the same draft token. Result is now cached for the lifetime of the PHP process. -300…-1000 ms per turn.
* **E3 — HTTP/1.1 keep-alive.** All OpenAI ChatKit, OpenAI Vision, and Twilio Verify wp_remote_post calls now request `httpversion => '1.1'` so cURL reuses the TLS connection across multiple OpenAI requests in the same PHP process.
* **A5 — get_request_photo_context never blocks on a cold cache.** Same pattern as A1: the tool returns whatever cached_analysis has and schedules a background refresh, so the assistant tool round-trip is always fast.
* **A6 — Photo analysis model auto-downgrades to gpt-4.1-nano for ≤2 photos.** Three or more photos still go to gpt-4.1-mini. -30…-50% wall-clock for typical 1–2 photo handyman uploads.
* **B2 — Photo + pricing context prefetched into ChatKit `state_variables`.** When the session is created, the plugin builds the same payloads `get_request_photo_context` and `get_request_pricing_context` would return and embeds them in `state_variables.photo_context` / `state_variables.pricing_context`. The Classification Agent can read those state values on turn 1 without firing those tools, saving 2 client-tool round-trips per first turn.
* **E4 — Logger buffers entries and flushes once on shutdown.** Per-turn 5–15 `info` entries used to be 5–15 separate `update_option` calls (= 5–15 DB writes + autoload cache invalidations). Now they're collected in memory and flushed once via the WordPress `shutdown` action. Errors and criticals still flush immediately to survive fatal exits.

= 2.1.8.5 =
* **Operational dashboard (A1)**: replaces the static metadata page. Five blocks — Today / Tomorrow / This week stat strip, Next 5 visits compact list, Action-needed chips (drafts / ready-not-booked / unsafe / errors), This-month-at-a-glance (count, revenue estimate, avg duration), and the changelog collapsed. All times in Eastern. Aggregate counts cached for 60 seconds.
* **Bookings list (B1)**: mobile cards on <1024px and a 5-column desktop table; filters Time/Status/Search persist in the URL; upcoming first, then a dated divider, then past.
* **Booking detail (B2)**: sticky top action bar with Call / Apple Maps / Cal.com link; "At a glance" 4-cell grid with copy-on-tap phone/email; photos surfaced with lightbox; assistant summary + estimate as a printable-style block; technical details and chat-activity collapsed.
* **Real chat transcript (B3)**: new `*_handik_messages` table (migration 1.3.0), `Messages_Service`, `/messages/record` REST endpoint, and ChatKit-bridge auto-mirroring of user/assistant messages with de-dupe. Booking detail now shows a real conversation in chat bubbles instead of grepped log entries.
* **Booking actions (B4)**: Add private note (modal + textarea), Mark as cancelled, Mark as completed. New `bookings.admin_notes` and `bookings.admin_status_override` columns persist these — Cal.com webhook updates respect manual overrides.
* **Unified People view (C1)**: one row per contact with addresses / requests / bookings counts and last-seen-relative time; filters All / With bookings / Drafts only / No address; debounced search by name/phone/email; new `contacts.is_spam` column with one-click hide and a "Show N hidden" toggle.
* **Person detail (C1+C3)**: header with phone/email tap-actions, inline edit form (name, phone, email, notes, returning, spam), per-address actions (set primary, full edit modal, soft-delete via `addresses.deleted_at`), and unified Requests/Bookings list. Phone changes log a warning entry.
* **Request detail (C2)**: new page `?page=handik-booking-app-crm&request_id=N` with the same blocks as a booking minus Cal IDs; banner explaining where the customer dropped off; "Send the customer their booking link" mailto for ready-not-booked.
* **Add person (C3)**: form to manually add a contact (and optional initial address) from the admin.
* **App Setup re-org (D1-D4)**: 6 tabs — Booking flow / Appearance / Service catalog / Service area / Cal.com / Customer notifications. Each setting key now appears exactly once. Cal.com event URLs and the new fallback URL moved out of Integrations into App Setup. Removed the General tab; Behavior moved into Appearance.
* **Service catalog editor (D2)**: drag-to-reorder via SortableJS, inline editing with auto-save (`POST /admin/catalog`), per-task Duplicate, soft-confirm Delete, "Saving / Saved / Failed" status indicator, "in use by N requests" badge.
* **Customer notifications (D4)**: editable Cal.com confirmation note and magic-link email subject/body with `{{placeholders}}` (`{{request_id}}`, `{{customer_name}}`, `{{address}}`, `{{task_summary}}`, `{{magic_link}}`, `{{site_name}}`). Wired into `class-cal-service.php` and `class-auth-service.php` so changes actually reach the outbound emails.
* **System info page (D5)**: plugin/DB/PHP/MySQL/WP versions, last-migration timestamp, cron status, total counts, "Run pending migrations" / "Clear plugin transients" / "Export tables to CSV" tools, raw-tables debug view, configurable log retention.
* **Logs (E1+E2)**: full level set — `debug / info / notice / warning / error / critical` — with new `Logger::warning()`, `Logger::notice()`, `Logger::critical()` methods and per-level retention (default 2000 / 500). Card-list rendering with collapsible JSON details, filters by level / time / request_id / thread_id / search (URL-persistent), CSV export. `request_id` and `thread_id` in log entries are clickable links.
* **Mobile-first admin CSS (F1)**: responsive grids, ≥44px tap targets, ≥16px form inputs to prevent iOS zoom, sticky bars with `-webkit-sticky`, horizontally-scrolling tabs, tables that degrade to card lists at <1024px.
* **Bottom nav on mobile (F1)**: fixed bar with Dashboard / Bookings / People / Setup / Logs visible only at <768px; safe-area-aware padding.
* **Toasts, modals, lightbox (F2)**: replace native `confirm()` and silent saves; copy-on-tap for phone/email; debounced search forms; in-button spinners.
* **REST surface**: new admin endpoints (`/admin/booking/*/notes`, `/admin/booking/*/status`, `/admin/contact[/*]`, `/admin/address/*[/primary]`, `/admin/catalog`, `/admin/migrations/run`, `/admin/transients/clear`, `/admin/export/*`) — all gated behind `manage_options` and the `wp_rest` nonce. Public `/messages/record` for the bridge mirror, gated by the customer's `draft_token`.
* **Reorganized PHP**: admin code split from a 1k-line monolith into `includes/admin/*` (helpers, dashboard, bookings, people, settings, logs, system, integrations) with a thin coordinator in `includes/class-admin.php`.

= 2.1.8.3 =
* Restored the last known working app backup from handik-booking-app-working-backup-20260504-225526-89afa72.zip.
* Reverted the admin rewrite and DB 1.3.0 migration changes from the 2.1.8.2 release path.
* Published the restored app as a newer rollback release so WordPress can update safely from 2.1.8.2.
= 2.1.8 =
* Polished first-screen footer spacing and quieted the global helper links.
* Pinned the Selected tasks & rates sheet to the bottom of the app with safe-area support.
* Fixed Contact details validation so required fields do not show red until blur or Continue, and reordered Phone above optional Email.
* Added a short saved-address loading state for recognized returning clients.
* Hardened the assistant-to-booking gate so empty chat or incomplete assistant results cannot open booking.

= 2.1.7 =
* Rebuilt Cal.com booking URLs with a safe encoded query builder, including attendee phone, location JSON, metadata, notes, and duration.
* Added Cal URL debug logging and forced old pre-encoded Cal URLs to rebuild instead of being reused.

= 2.1.6 =
* Added bootstrap caching, ChatKit session prewarm on Address details, and an optimistic typing indicator for assistant replies.
* Verified the 6-step no-OTP flow and tightened the final audit cleanup for the global footer and assistant loading path.

= 2.1.5 =
* Removed the New/Returning and SMS verification screens from the customer booking flow.
* Added silent returning-client lookup by phone on the Contact step and saved-address handoff to Address details.
* Fixed stale Cal.com duration reuse and reconciled booking type with suggested duration.
* Cleaned photo/video copy, global restart/direct-booking helper links, selected-task animation, and assistant fallback rendering.

= 2.1.4 =
* Reordered intake so task selection starts the flow, photos/videos come before client type, and address/contact happen after returning-client verification.
* Added phone-only returning-client verification UI, video upload storage/previews, assistant video-context exposure, and stricter assistant booking gating/logging.
* Replaced normal Back on assistant/booking with a confirmed Start a new booking reset and added selected-task mismatch flags for admin review.

= 2.1.3.18 =
* Split technical Cal URL readiness from true assistant booking readiness so `enough_information=false` keeps clients on the assistant step.
* Added a fallback assistant message panel that shows `next_message` if ChatKit saves the routing result but does not visibly render the assistant response.
* Blocked `/booking-url` unless the saved assistant result has enough information, complete routing, and is not unsafe.

= 2.1.3.17 =
* Stopped frontend phone masking and `+1` rewriting on Contact details and Returning client verification; raw phone input is now sent to the backend for normalization.

= 2.1.3.16 =
* Disabled native browser, Safari, keyboard, and password-manager autofill hints on the job address fields so Google Places remains the only address suggestion source.

= 2.1.3.15 =
* Locked the assistant-to-booking gate so the Cal step only opens after a saved routing result returns a ready booking URL and complete booking type, duration bucket, and suggested duration.
* Kept incomplete assistant events on the assistant step with a `Preparing booking recommendation...` state instead of saving fallback routing or building Cal URLs.
* Blocked the `/booking-url` endpoint from fallback-routing drafts that do not already have complete saved assistant routing.
* Kept the assistant continue button pending until ChatKit finishes responding after the routing tool save, with a short fallback unlock if the final assistant-message event is not emitted.

= 2.1.3.14 =
* Locked in the first valid assistant-generated Cal booking URL so later fallback routing cannot switch the customer to a different event type.
* Prevented empty or incomplete `save_assistant_routing_result` tool calls from overwriting valid stored routing.
* Skipped repeated Cal embed remounts for the same URL and added a direct mobile fallback link on the booking step.
* Shortened Cal.com notes so full photo analysis and assistant details stay in the WordPress admin dashboard.

= 2.1.3.13 =
* Standardized the bottom action bar so every screen after the first shows a consistent `Back` button and right-side action.
* Added `Choose option` guidance on the task-type screen and a `Choose time` action on the final calendar screen.
* Restored back navigation from the Virtual assistant step to Contact details.

= 2.1.3.12 =
* Added bottom navigation from the task-type screen back to client type, and from the specific-task browser back to task type.
* Stopped programmatically focusing step headings so browsers no longer draw a black focus ring around the `h2` after navigation.

= 2.1.3.11 =
* Hardened the REST surface: Cal.com webhook now fails closed when no shared secret is configured, `/client-log` is rate limited (60 entries/min/IP) and trims oversized payloads, and every front-end fetch now sends the WordPress REST nonce.
* Added per-field `autocomplete` and `inputmode` hints (name, email, phone, street address, one-time code) so mobile browsers can autofill correctly and show the right keyboard.
* Phone input now applies the display format as the user types while preserving caret position; submission still uses the canonical normalized number.
* Added local draft persistence: in-progress bookings are stored in `localStorage` (24-hour TTL, plugin-version pinned) and restored on reload, then cleared automatically once the booking is confirmed or the flow is restarted.
* Browser back/forward now navigates between booking steps via the History API instead of dropping out of the app.
* Focus moves to the new step heading on every navigation, toasts/validation errors expose `role="status"` / `role="alert"`, and animations honor `prefers-reduced-motion`.
* Photos can now be removed individually, the upload control shows the 10 MB limit, and the saved-address area shows an empty state when a returning client has no saved addresses.
* Cal.com booking embed and the assistant chat now render skeleton placeholders, and the booking embed shows an "open in a new tab" fallback if it does not load within 15 seconds.
* GET requests to the REST API retry with exponential backoff (1 s, 2 s, 4 s) on transient network or 5xx failures; POST requests still execute exactly once.
* Added a standardized `Handik_Booking_App_Api_Response` helper, fixed the dashboard "Recent requests/bookings" cards to use cheap COUNT queries, and memoized the service catalog parser.
* Added scaffolding for code-quality tooling: `.gitignore`, `composer.json` (PHPCS + PHPStan), `package.json` (ESLint + Prettier), `.editorconfig`, and a CI workflow that runs lint and static analysis on every PR.

= 2.1.3 =
* Added visible pricing hints to the three task-path cards on the `What do you need help with?` screen.
* Hid the separately promoted General Handyman Help and Larger-Scale Work services from the specific task browser.
* Changed the Selected tasks sheet to slide in first, then bounce two seconds later, and removed the assistant-ready info toast.
* Reduced Virtual assistant wait time by mounting ChatKit immediately while photo analysis warms in the background, and reduced bridge-side debug logging/network chatter.

= 2.1.2 =
* Added the `get_request_pricing_context` ChatKit client tool and WordPress endpoint so the assistant can fetch selected task rates, applied hourly rate, suggested duration, and rough labor/material/total estimates.
* Added pricing estimate fields to assistant result normalization, backend sanitization, and app state storage.
* Added pricing-context diagnostics for tracing assistant price answers through ChatKit, WordPress, and saved routing state.

= 2.1.1 =
* Added the `save_assistant_routing_result` ChatKit client tool so the workflow can explicitly persist the full routing payload instead of relying only on ChatKit structured-result events.
* Expanded assistant-result diagnostics with booking type, duration bucket, suggested duration, pricing posture, and routing persistence logs.
* Added Cal.com booking URL diagnostics showing the selected event type, suggested duration, converted duration minutes, pricing posture, and final booking URL.

= 2.0.61 =
* Reordered the booking flow so task choice comes before address details.
* Kept the bottom progress bar visible on the client and task entry screens, while making the progress strip thinner.
* Updated the Photos step title and subtitle and reduced the Virtual assistant chat height slightly.

= 2.0.60 =
* Removed the extra client-type Continue step so New client and Returning client immediately advance to the next screen.
* Reworked task selection into three large entry cards: General Handyman Help, Larger-Scale Work, and Choose Specific Tasks.
* Renamed the contact Continue action to `Continue to Assistant` and verified the Cal.com duration handoff still passes the assistant-suggested duration.

= 2.0.59 =
* Updated fallback routing to align with the new workflow schema and Cal.com duration setup, including `6_8_hours`, `suggested_duration_hours`, and `pricing_posture`.
* Routing now relies more on task type and complexity signals than raw task count, with better defaults for installation, premium specialty, exterior, and consultation-first work.
* Cal.com booking URLs now carry `duration` plus metadata for `suggested_duration_hours` and `pricing_posture`, and the ChatKit bridge/backend now preserve the new assistant-result fields.

= 2.0.58 =
* Updated the built-in service catalog to the latest task list from the attached planning file, including the renamed Electrical, Doors, Carpentry, and Larger-Scale Work entries.
* Increased the automatic step scroll reset offset from 70px to 80px to better clear fixed headers during screen transitions.
* Clarified the task-selection intro so it explains that tapping a service toggles it on and off, and removed the bold styling from the Photos `Tap to add photos` call to action.

= 2.0.57 =
* Constrained the mobile `Virtual assistant` step so the title, intro, chat area, fixed header offset, and bottom action bar fit into one viewport more reliably.
* Removed the hard inline chat min-height and tied the hosted ChatKit element to the available assistant host height on mobile screens.

= 2.0.56 =
* Added smooth screen-to-screen scroll reset so each new booking step returns to a consistent top anchor with space for a fixed header.
* Locked the app shell, screen body, and booking embed containers to full-width without horizontal overflow so the Cal.com step no longer introduces side scrolling.

= 2.0.55 =
* Added detailed admin log entries for returning-client verification, including contact lookup, selected delivery path, Twilio Verify request start, HTTP response status, and Twilio error payloads.
* Logged whether phone verification uses Twilio Verify or falls back to email/local flows so SMS issues can be diagnosed from the WordPress admin log screen.

= 2.0.54 =
* Added Twilio Verify settings for `Account SID`, `Auth Token`, and `Verify Service SID` in Integrations.
* Switched phone-based returning-client verification from the empty SMS hook path to real Twilio Verify SMS start and code check requests.
* Kept the existing email verification path in place as a fallback for email-based returning-client sign-in.

= 2.0.53 =
* Refined the `Selected tasks` bounce so the panel appears first, then performs a short up-down-up return instead of a single pop.
* Normalized older saved Virtual assistant text into the new shorter greeting and the clearer `Book a time` button label.
* Removed the extra bridge-side loading placeholder so the Virtual assistant step now shows only one loading animation layer while chat initializes.

= 2.0.52 =
* Trigger the `Selected tasks` bounce animation only when the sheet first appears after going from zero selected tasks to one.
* Hide the `Selected tasks` sheet when all tasks are removed and re-arm the one-time bounce animation for the next first task.
* Render admin booking addresses with the apartment suffix as `Apt ...` and tighten the Virtual assistant bridge loading spacing so it better matches the rest of the app.

= 2.0.48 =
* Fixed App Setup persistence so saving one tab no longer resets other tabs or wipes Integrations settings.
* Moved Integrations into a dedicated admin section together with Logs and Changelog for safer daily configuration changes.
* Redesigned the Bookings list and booking details around client, task, address, photos, and Eastern Time scheduling data, with mobile-friendly admin layouts.
* Removed task-selection notifications, simplified photos/contact/assistant screen copy, updated footer button styling, and removed shell/screen box styling so the app can inherit its parent Elementor container.
* Switched the frontend loaders to the new loadbar style and added client-side image downscaling to speed up large photo uploads on mobile.

= 2.0.51 =
* Stopped forcing phone formatting on every keystroke so the phone field now accepts normal typing and only normalizes the value for validation and API payloads.
* Added a bounce-style entrance animation to the collapsed `Selected tasks` sheet so it is easier to notice when tasks are selected.
* Included `Unit or apartment` in the admin booking address summary, updated the Virtual assistant fallback copy, and tied the assistant overlay more closely to real chat readiness.

= 2.0.50 =
* Matched the loadbar animation more closely to the provided loader reference by restoring the original stripe angle and spacing.
* Removed the ChatKit startup probe that could interfere with the initial widget lifecycle on the Virtual assistant step.
* Stopped the assistant preparation flow from forcing a full screen re-render during chat mount, which fixes the white-screen refresh loop.

= 2.0.49 =
* Reworked the frontend loader to use the exact `sp-loadbar` style and `Loading` label from the provided loader reference file.
* Restored a safer Virtual assistant loading lifecycle so chat mount no longer re-renders itself into a white-screen loop while the assistant is initializing.
* Updated the hosted ChatKit bridge loading placeholder to match the new loader style and keep assistant loading tied to chat readiness.

= 2.0.47 =
* The hosted ChatKit bridge now binds `get_request_photo_context` both in `options.onClientTool` and directly on the mounted web component as `element.onClientTool`, covering runtimes that only honor live element callbacks for client tools.
* Added explicit info-level diagnostics for client-tool invocation, fetch start/completion, payload return, and a browser-side tool probe so it is clear whether the runtime can complete the tool call before the user sends a message.
* Client-tool failures now return a safe JSON fallback payload instead of leaving the workflow with `No output`, reducing the chance that the assistant stalls indefinitely at the Classification Agent.

= 2.0.46 =
* The hosted ChatKit bridge now applies its options through both the element `options` property and `setOptions()`, then reapplies them after the widget is attached to the DOM so browser-side client tools are registered on the live element.
* Added a bridge diagnostic for option application with `onClientTool` enabled, which makes it easier to see whether the client-tool handler was actually attached before the workflow calls `get_request_photo_context`.
* This hotfix targets the stalled-assistant issue where the workflow invoked the photo-context tool but the browser-side handler never fired, leaving the chat stuck at the Classification Agent.

= 2.0.45 =
* Added a dedicated `request-photo-context` endpoint so hosted ChatKit can retrieve uploaded-photo analysis and visual estimate context directly from WordPress instead of relying on hidden thread messages.
* The ChatKit bridge now supports the hosted client tool `get_request_photo_context` and returns the current request's photo context into the workflow as a proper tool result.
* Removed the assistant step's dependency on `HANDIK_CONTEXT` thread injection, leaving photo warmup as a preparation step while the live assistant now fetches visual context through the supported client-tool path.

= 2.0.44 =
* Photo-analysis failure and inconclusive paths now persist a complete `photo_analysis` object with a stable signature instead of leaving the assistant gate with an empty `photos_signature`.
* The assistant photo-context dispatcher now uses a fallback dispatch signature when needed, so `HANDIK_CONTEXT` can still be sent into the live thread even when uploaded-photo analysis is limited or unavailable.
* This closes the silent skip where the assistant unlocked normally but never dispatched any uploaded-photo context simply because the stored analysis object did not carry a usable signature.

= 2.0.43 =
* The ChatKit bridge now fires its assistant session-ready callback from the first real interactive chat signal instead of depending only on the hosted `chatkit.ready` event.
* Added bridge diagnostics for the session-ready callback source and a timeout-based forced callback after an interactive mount when the hosted ready event never arrives.
* This prevents the Virtual assistant loading overlay from hanging forever just because ChatKit became usable without emitting the exact ready event the booking app was waiting for.

= 2.0.42 =
* The Virtual assistant step now keeps its loading overlay in place until uploaded-photo analysis is ready and the `HANDIK_CONTEXT` message has been dispatched into the live ChatKit thread.
* Added explicit assistant photo-gate logs for gate start, analysis readiness, context dispatch, and composer unlock to show whether photo context reached the thread before the customer could type.
* Closed the old timing gap where ChatKit could become interactive before the uploaded-photo summary was injected, allowing the first user message to hit OpenAI without visual context.

= 2.0.41 =
* The Services & Categories editor now saves reliably because dynamic category and service field names are renumbered in the admin UI and the backend can rebuild the catalog directly from submitted form fields instead of depending only on the hidden JSON payload.
* Replaced the old starter catalog with the new eight-category Handik service map covering Assembly, Plumbing, Electrical, Interior Finishes, Doors and Windows, Carpentry, Exterior work, and Not Sure / Project Help.
* Added legacy-catalog detection so older installs that still point at the original built-in catalog automatically fall forward to the new default service structure.

= 2.0.40 =
* Removed the old info-mode toggle and simplified booking-app notifications to always-on error, warning, and info messages, while dropping the older success and task-specific toast types.
* Address details now require a valid Google-suggested address before Continue activates, Contact details validate full name, email, and phone formats live, and the task step now includes a sticky Selected tasks bottom sheet with descriptions and hourly rates.
* The Virtual assistant step now relies on the stable WordPress-first photo-analysis pipeline instead of hosted ChatKit file handoff attempts, so uploaded photos are saved locally, analyzed on the backend, and prepared before the assistant opens.

= 2.0.39 =
* The Virtual assistant step now warms uploaded-photo analysis before the first hosted ChatKit mount for new photo-backed requests, removing the old race where the assistant could start before photo context was ready.
* ChatKit `onSessionReady` callbacks now receive the full session payload so the booking app can use server-provided draft-context photo analysis when handing uploaded-photo context into the live chat.
* Added an assistant preparation loading state and a more predictable photo-context handoff sequence for hosted ChatKit.

= 2.0.38 =
* When backend photo-analysis finishes, the booking app now sends a compact `HANDIK_CONTEXT` message into the live hosted ChatKit thread so the assistant can use the uploaded-photo summary during the current conversation.
* The context injection includes photo summary, estimate notes, visible tasks, and visible cautions, and it is de-duplicated by photo signature so the same photo batch is not repeatedly announced.
* Added explicit logs for assistant photo-context message start, completion, and failure so it is clear whether the uploaded-photo summary actually reached the live chat thread.

= 2.0.37 =
* Stopped forcing a full assistant-step rerender while `Add photos for AI review` uploads files, because that rerender was remounting the hosted ChatKit widget and breaking the live thread before the next user message.
* The assistant photo button now uploads and warms photo-analysis in the background without rebuilding the chat host, so the same chat instance stays mounted and ready to accept the next message.
* Non-assistant photo uploads keep their normal loading overlay behavior, but the Virtual assistant step now preserves the active chat surface during AI-review photo saves.

= 2.0.36 =
* Stopped sending `Add photos for AI review` files into the hosted ChatKit composer because that native file handoff was causing the assistant conversation to stop responding after the next user message.
* The assistant-step photo button now saves the images to WordPress CRM and immediately warms backend photo-analysis from those saved request files instead of trying to attach them inside the chat composer.
* This keeps the chat responsive while preserving the intended outcome: the AI can still review the uploaded photos and the CRM still stores them on the request.

= 2.0.35 =
* Added timeout protection around the assistant `addFiles` handoff so `Add photos for AI review` cannot leave the Virtual assistant screen spinning forever.
* Moved backend photo-analysis warmup out of the blocking upload chain so saved request photos can continue analyzing in the background after the UI loader is released.
* Added focused assistant photo-flow logs for addFiles start, completion, timeout/failure, and photo-analysis warmup start/completion.

= 2.0.34 =
* Enabled hosted ChatKit composer attachments so `Add photos for AI review` can feed files into a fully attachment-capable native chat upload path.
* Kept the same assistant-step photo picker dual-uploading the selected images into WordPress CRM while the chat side now uses the supported file-attachment configuration.
* Mirrored file-upload limits from the ChatKit session into the composer settings and expanded the session log context with the received upload configuration.

= 2.0.33 =
* Rolled back the experimental assistant photo-upload diagnostics that interfered with hosted ChatKit connection stability on the Virtual assistant step.
* Restored the previously working assistant photo flow from 2.0.31 so OpenAI chat sessions can mount and run normally again.
* Kept the assistant-step `Add photos for AI review` control while returning the bridge behavior to the last stable release path.

= 2.0.31 =
* Added an assistant-step `Add photos for AI review` control so clients can upload images right where they ask the AI for help.
* Those files now dual-upload: they are stored in WordPress CRM and also queued into the hosted ChatKit composer for the next user message.
* Kept backend photo analysis in place so the WordPress copies still feed AI visual context even if hosted ChatKit file prefill is not accepted.

= 2.0.30 =
* Added a backend OpenAI vision pass that analyzes uploaded WordPress request photos and caches the result on the draft request.
* Warmed photo analysis in the background on the Virtual assistant step and merge the visual observations into estimate notes before final routing is saved.
* Added cached uploaded-photo analysis to the admin booking detail view so operators can see what the system inferred from the images.

= 2.0.29 =
* Keeps photo File objects in frontend memory after the Photos step while still uploading them to WordPress for CRM storage.
* Preloads pending photos into the hosted ChatKit composer so the client can send the first real assistant message together with the same images.
* Clears the pending photo queue after composer submit so attachments are not silently duplicated on later assistant visits.

= 2.0.28 =
* Reworked the booking flow order to run through address, tasks, photos, contact details, virtual assistant, and booking with the sticky action dock fixed to the bottom on desktop and mobile.
* Added richer booking admin details with assistant output JSON, direct OpenAI thread-log links, and smarter booking list columns built around client info, task summary, hourly rate, address, assistant summary, and schedule.
* Removed the app-owned success step so the Cal.com confirmation view can remain the final booking state, while also reducing duplicate assistant mounts that were causing unstable chat behavior.

= 2.0.27 =
* Made the Info mode button smaller, calmer, and less decorative so it reads as a lightweight utility control.
* Extended the toggle to hide warning notices as well, while still leaving success and real error messages visible.
* Added short forced status messages when the toggle changes so clients see a clear “Hints are enabled” or “Hints are disabled” confirmation.

= 2.0.26 =
* Added an Info mode toggle with a short onboarding tooltip and cached client preference so helper tips can stay on or off between visits.
* Simplified client-type and task notifications by removing extra titles, removing the task-details pill, and keeping task notices focused on the description plus hourly rate.
* Updated warning copy and softened the notification animation so interactive notices feel more natural in the booking flow.

= 2.0.25 =
* Moved interactive notifications into `document.body` so they are no longer trapped inside the booking app container's stacking context.
* Fixed the case where a fixed site header or menu could still appear above notifications despite the toast layer using a very high z-index.
* Kept the same compact desktop bottom-right placement and top placement on mobile while making the layer behave like a true global overlay.

= 2.0.24 =
* Reduced desktop interactive notifications and moved them to the bottom-right corner to better match the intended compact reference style.
* Kept mobile notifications at the top with a much higher z-index so they stay above the rest of the app UI.
* Fixed notification pause and resume timing so the countdown bar and auto-dismiss continue from the remaining time instead of visually restarting.

= 2.0.23 =
* Replaced the old inline hints, footer bubbles, and assistant helper blocks with one unified interactive notification layer across the booking flow.
* Added success, error, warning, info, and task-description notifications with richer dark styling, timed progress bars, and pause-on-hover or touch-hold behavior.
* Task selections, client-type choices, and blocked Continue actions now use the new notification system instead of scattered tooltip and hint UI.

= 2.0.22 =
* Moved Bookings into its own admin menu section and regrouped requests with contacts and addresses under a clearer Clients & Requests CRM area.
* Added a detailed booking view with booking, client, request, address, photo, and saved chat-activity information.
* Expanded admin styling for booking details with cards, chips, map embed support, photo galleries, and structured activity blocks.

= 2.0.21 =
* Switched Cal.com loading to the official bootstrap-snippet pattern so the embed exposes `window.Cal` and namespace APIs reliably.
* Fixed the booking-step failure where the app fell back to iframe mode because the previous integration assumed a plain script include was enough.
* Kept the new Cal embed diagnostics and booking-capture path on top of the corrected bootstrap flow.

= 2.0.20 =
* Removed the old framed booking status banner so the final booking step stays visually clean around the embedded calendar.
* Added detailed Cal embed client logs for mount, ready, failure, and success callbacks to diagnose booking sync issues in WordPress logs.
* Registered both global and namespaced Cal embed booking-success listeners to improve compatibility with Cal.com event delivery.

= 2.0.19 =
* Booking step now listens for Cal embed success events and captures confirmed bookings into the local CRM immediately instead of waiting only for webhook delivery.
* Added a backend booking-capture endpoint so the booking screen can finish the flow when Cal.com confirms the slot inline.
* Refreshed the booking screen status UI to match the rest of the app instead of using the old plain confirmation note block.

= 2.0.18 =
* Virtual assistant Continue now treats an active or restored ChatKit thread as valid interaction when hosted ChatKit drops the expected user-message event.
* The assistant step can move forward after a real conversation even if the embedded hosted ChatKit UI throws the React thread-event glitch in the browser console.
* Thread association now acts as a fallback signal so clients are not blocked by the old `Please send the virtual assistant...` warning after they already used the chat.

= 2.0.17 =
* Photo uploads now require a valid draft request and draft token before the backend accepts files.
* Removed absolute server file paths from upload responses.
* Fixed returning-client history loading to pull the contact's recent requests directly from the database.

= 2.0.16 =
* Added an admin-managed service and category editor so the task-selection screen can be configured without code edits.
* Split address and photos into separate steps and store uploads in per-contact or per-session request folders.
* Removed client-card subtitles, switched client-type help to cleaner info toasts, and kept continue warnings anchored to the footer button.

= 2.0.15 =
* Stabilized hosted ChatKit by removing unsupported composer upload configuration and unsupported composer-prefill commands that were crashing the embedded chat.
* Switched assistant draft handoff to a safer auto-sent context message so the same chat can resume more reliably.
* Removed the first-screen Back button and upgraded client-choice plus Continue validation hints into attached bubble-style tooltips.

= 2.0.14 =
* Added a much larger admin-side UI control surface for texts, labels, colors, helper copy, and scoped custom CSS overrides.
* Updated the booking wizard with muted Continue states, footer tooltip validation, client-type helper cards, a clearer photo uploader, and unit or apartment support.
* Improved assistant-step persistence plus Cal.com webhook matching with metadata, booking ID, and contact-based fallback matching for booking confirmations.

= 2.0.13 =
* Changed GitHub auto-update checks to a 24-hour cadence instead of the shorter default interval.
* Kept the manual `Check for updates` link in the WordPress Plugins screen for on-demand checks.
* Throttled the updater initialization log so it only appears once per day or when updater settings change.

= 2.0.12 =
* Virtual assistant Continue now re-checks the stored draft classification on the backend before blocking the user.
* The assistant-result endpoint now merges the incoming payload with any previously saved ChatKit classification from the same draft request.
* Added assistant-result logs so it is easier to diagnose whether routing used stored enough-information data or needed more client input.

= 2.0.11 =
* Virtual assistant Continue now stays muted until the workflow returns `enough_information = true`.
* Clicking Continue too early keeps the user on the same step and shows the inline assistant notice.
* Once enough information is captured, the same button turns green and acts like the normal next-step action.

= 2.0.10 =
* Hardened structured assistant-result capture so classification output can be detected by schema shape, not only by specific effect names.
* Added fallback extraction from ChatKit effect, deeplink, message, and log payloads.
* Broadened chat-interaction detection so Continue is less likely to block after a real user conversation.

= 2.0.9 =
* Fixed the virtual assistant Continue flow so it no longer remounts the chat before moving forward.
* Continue now blocks only when the client has not sent anything in chat and there is no assistant result with `enough_information = true`.
* Assistant notices now update inline on the current screen instead of forcing a full assistant step rerender.

= 2.0.8 =
* Replaced simple loaders with custom handyman and virtual-assistant animations, including delayed helper text for longer loads.
* Cleaned up the assistant step copy and removed the extra fallback textarea plus reload control.
* Desktop back buttons are now text-only while mobile keeps a cleaner icon-based back action.

= 2.0.7 =
* Added playful loading states, centered desktop client-choice buttons, and sticky mobile footer actions.
* Removed preferred timeframe, simplified the assistant screen, and require at least a short task description before continuing without a completed chat result.
* Reuse the same assistant thread when going back and updated Cal.com attendee-address plus phone prefills.

= 2.0.6 =
* Reworked the booking flow for mobile-first use and full-width layout.
* Added Google Maps autocomplete to the single-line job address field.
* Embedded the Cal.com booking step and wait for webhook-confirmed booking status before showing success.

= 2.0.5 =
* Added GitHub release updater with private-repository token support.
* Added release-asset matching for WordPress ZIP downloads.
* Added release packaging workflow for GitHub Releases.

= 2.0.4 =
* Removed the welcome screen so the app starts at client type selection.
* Added assistant-step navigation controls and manual continue.
* Removed short description from the address step.

= 2.0.3 =
* Improved hosted ChatKit mount diagnostics and frontend logging.

= 2.0.2 =
* Removed unsupported `state_variables` from hosted ChatKit session requests.

= 2.0.1 =
* Improved ChatKit session diagnostics and OpenAI project scoping support.

= 2.0.0 =
* Rebuilt the plugin into a standalone booking application.
