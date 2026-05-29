=== Handik Booking App ===
Contributors: handik
Requires at least: 6.4
Requires PHP: 7.4
Tested up to: 6.6
Stable tag: 2.1.34.0
License: Proprietary

Single-page booking application with AI-assisted intake, multi-day project scheduling, and end-to-end Cal.com calendar sync.

== Description ==

Handik Booking App owns the entire booking experience. The plugin renders the customer-facing flow itself, persists everything to a local CRM, and stays in lockstep with Cal.com — so a cancel or reschedule in the admin propagates to the customer's calendar automatically.

= Customer-facing flows =

* `[handik_booking_app]` shortcode (or Elementor widget) — single-page wizard with an OpenAI ChatKit AI assistant. Customer chats through their job, the assistant produces a structured intake result, and the plugin routes them to the right Cal.com event for slot selection.
* `[handik_direct_booking_form preset_slug="..."]` — Additional Forms direct preset. Phone OTP → contact + address → Cal embed → booked.
* `[handik_project_day_form preset_slug="..."]` — Additional Forms project preset. Phone OTP → contact + address → pick N work days → server creates N Cal bookings sequentially with rollback on partial failure.

= Operator-facing admin =

* Unified Bookings list — every booking source (main SPA, direct form, project form, external Cal-only) lands in one place
* People & Requests — contacts, addresses, transcript persistence, drafts focus list with bulk-delete
* Bookings detail — at-a-glance + actions bar (Note, Reschedule, Completed, Cancelled), cancellation/delete auto-propagates to Cal.com
* Reschedule from the booking detail — pick a new date/time, the customer's Apple / Google / Outlook calendar moves the event in place
* "Pull from Cal.com" — backfill bookings that were made directly on Cal.com (e.g. customer used the "Open the booking page directly" fallback link) or that arrived during a webhook outage
* Bulk actions on every list — select multiple, cancel-on-Cal + cascade delete locally with a typed confirmation + optional Cal reason
* OpenAI-powered "Load chat from OpenAI" — backfill the chat transcript from ChatKit's authoritative thread storage when the in-browser bridge missed events
* Dashboard with action-needed chips — drafts older than 24h, ready-but-not-booked, unsafe, errors today

= Integrations =

* OpenAI ChatKit (assistant flow + transcript backfill)
* Cal.com v2 API (booking create / cancel / reschedule + webhook receiver with HMAC signature verification)
* Google Maps Places (address autocomplete on the customer forms)
* Twilio Verify (phone OTP on Additional Forms)
* wp_mail with branded HTML templates + `.ics` calendar invite attachment for customer + owner confirmation, cancellation, and reschedule emails

= Embedding =

The booking shortcodes work in any WordPress page / post / block. An Elementor widget is registered for drag-drop placement. The main SPA + Additional Forms share a stylesheet, so visual customization via the admin Appearance tab applies to both.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin. Migrations run automatically on the first admin page load.
3. Open `Handik Booking → Settings` and configure:
   * **OpenAI** — API key + workflow id for the AI assistant
   * **Cal.com** — API key, webhook secret, and the event-type slugs / IDs for each booking type
   * **Google Maps** — Places API key for address autocomplete
   * **Twilio Verify** — for phone OTP on Additional Forms
   * **Email** — from-address + from-name for customer / owner notifications
   * **Updater** — GitHub repo and (for private repos) personal access token so the WordPress auto-update system can find new releases
4. Add `[handik_booking_app]` to a page, or use the Elementor widget. For Additional Forms, configure presets under `Handik Booking → Additional Forms` and embed via `[handik_direct_booking_form preset_slug="..."]` or `[handik_project_day_form preset_slug="..."]`.
5. (Optional) Enable auto-updates on the WordPress Plugins screen so future GitHub releases land automatically.

== Requirements ==

* WordPress 6.4+
* PHP 7.4+ (tested through PHP 8.2)
* Active Cal.com account with API access (Cal Atoms or Cal.com Cloud Pro+) — required for the booking-creation, cancel, reschedule, and webhook lifecycle
* OpenAI API key + ChatKit workflow — required for the main SPA assistant flow. The Additional Forms presets work without OpenAI.
* MariaDB 10.3+ / MySQL 5.7+

== Upgrade Notice ==

= 2.1.28.1 =
Documentation refresh — no functional change. The plugin is identical to 2.1.28.0 at runtime; this release exists to publish the updated README, ARCHITECTURE.md, RELEASE_CHECKLIST.md, and an expanded readme.txt so a fresh `git pull` / WordPress auto-update brings the new docs to anyone running an earlier 2.1.x build. Safe upgrade — no DB migration, no settings touched.

= 2.1.28.0 =
Adds Reschedule from the admin Bookings detail. Pick a new date/time and the customer's calendar invite moves in place via Cal.com. Completes the unified booking lifecycle started in 2.1.27.0 (cancel + delete already propagate to Cal). No DB migration; safe upgrade.

= 2.1.27.0 =
Cancel + delete in the plugin now auto-cancel on Cal.com so the customer's Apple / Google / Outlook calendar invite updates without manual cleanup. Adds reason prompts on cancel/delete flows. No DB migration.

= 2.1.26.0 =
Schema migration 1.6.1 adds `external_contact_id` for backfilling bookings made directly on Cal.com. Adds "Pull from Cal.com" button. Migrates automatically on first admin page load after upgrade.

= 2.1.23.0 =
Schema migration 1.6.0 adds `project_work_day_id` so multi-day project bookings show up in the unified admin Bookings list. Migrates automatically.

== Changelog ==

= 2.1.34.0 =
* **Sprint 4 / Customer unification — property-level attributes + pre-visit briefing.** Customer attributes (1.6.4) answer "who is this person"; property attributes answer "what's true about THIS address" — gate code, parking, pets, building hazards. They live on the address, not the contact: if the customer moves, the gate code doesn't follow them. One additive migration, no breaking change.
* **Migration 1.6.5** — adds to `handik_addresses`: enums `building_type`, `parking`, `building_age_class`; access codes `gate_code` / `lockbox_code` / `alarm_code` (stored raw, masked in the UI); booleans `doorman`, `freight_elevator_required`, `pets_present`, `asbestos_warning`, `mold_present`, `hoarding_situation`; texts `freight_elevator_hours`, `parking_notes`, `pets_notes`; and a `property_notes` textarea. Safe DEFAULTs, idempotent.
* **`Addresses_Service`** gains the canonical property-attribute schema (`attribute_enums()` / `attribute_booleans()` / `attribute_sensitive()` / `attribute_texts()`), shared by the sanitizer, the edit modal, and the REST allowlist. `admin_update()` validates enums against the allowed set, coerces booleans, and stores codes/texts/notes.
* **Pre-visit briefing block** on the Bookings detail (above Tasks), assembled by the new `Customer_View_Service::pre_visit_briefing( $contact, $address )`. Three groups — Customer (language, preferred channel/time, payment, do-not-text), Property (building, access codes, parking + notes, 🐕 pets, ⚠ hazards), Internal flags (VIP, scope creeper, do-not-service, tips/payment behavior). Only set attributes appear. Access codes render masked (`••••`) with a "show" reveal toggle + copy-to-clipboard. The operator opens a booking on the way over and sees everything at a glance.
* **Address edit modal** (Customer detail → Addresses → Edit) now includes the full property-attribute set: enum selects, hazard/pet checkboxes, code/note inputs, property-notes textarea. The modal fetches the full row via the new `GET /admin/address/{id}` so every field pre-fills (with a data-attr fallback for the core fields).
* **New REST endpoint** `GET /admin/address/{id}` (READABLE) returns the full address row for the edit modal. `PATCH /admin/address/{id}` allowlist expanded from the canonical schema. Both `admin_permission` gated.
* Apple Maps deep-link is intentionally left as a pure address geocode — parking guidance surfaces in the briefing's "Parking" row (appending free text to the maps `q=` would break the geocode).
* No customer-facing change. Migration auto-runs on next admin load.

= 2.1.33.0 =
* **Sprint 3 / Customer unification — customer-level structured attributes.** Replaces the single free-text `notes` textarea as the only place to record what you know about a customer. Adds the service-CRM pattern (Jobber / Housecall Pro): enums + boolean flags for recurring attributes, a flexible tags multi-select, and `brand_preferences` short text — with `notes` kept as the free-form catch-all at the end of the form. One additive migration, no breaking change.
* **Migration 1.6.4** — adds to `handik_contacts`: enums `language` (en/ru/both), `preferred_channel`, `preferred_time`, `payment_method_preferred`, `tips_well`, `payment_on_time`; booleans `do_not_text`, `requires_invoice`, `vip`, `do_not_service`, `scope_creeper`, `negotiates_hard`, `complains_after`, `eco_friendly_only`; `brand_preferences` text; and a JSON `tags_json` column. Indexes on `vip` + `do_not_service` for fast list filtering. All columns ship with safe DEFAULTs — existing data untouched; nothing is auto-migrated out of `notes` (operator repacks through usage). Idempotent.
* **`Contacts_Service`** gains the canonical attribute schema (`attribute_enums()` / `attribute_booleans()`), shared by the sanitizer, the admin UI, the list filters, and (later) the notifications language switch + pre-visit briefing. New helpers: `normalize_tags()` (array or comma-string → de-duped, trimmed, capped 30×40), `decode_tags()`, `top_tags()` (most-used N for autocomplete). `admin_update()` now validates enums against the allowed set (invalid → unset), coerces booleans, normalizes + JSON-encodes tags, and stores `brand_preferences`.
* **Customer edit form redesigned** — structured fields FIRST, grouped (Communication / Payment / Flags & preferences / Tags), free-form notes LAST. Enums render as `<select>`, flags as checkboxes, tags as a comma-separated input backed by a `<datalist>` of the top-20 used tags. The admin save path now collects every `[data-field]` generically (checkboxes as booleans) so new attributes flow through without per-field JS.
* **REST** `PATCH /admin/contact/{id}` allowlist expanded from the canonical schema (core fields + all enums + all booleans + `brand_preferences` + `tags`). Same `admin_permission` gate.
* **Customers list** — new quick-filter chips: **VIP**, **Russian-speaking** (language ru/both), **Do not service**; plus a free-form `?tag=` filter that composes with any chip. Each person row now surfaces VIP / do-not-service pills and up to 3 tag chips inline (with a "+N" overflow marker).
* No customer-facing change. Migration auto-runs on next admin load. Existing notes preserved and still shown (now under the "Notes" sub-heading).

= 2.1.32.0 =
* **Sprint 2 / Customer unification — pre-approval customer picker + Bookings source filter.** Builds on the Sprint 1 Customer 360 read-model. No breaking change; one additive migration that auto-runs.
* **Migration 1.6.3** — adds nullable `contact_id` + `idx_contact_id` to `handik_form_approvals`, then a one-time backfill linking existing approvals to a contact when the normalized phone matches exactly one `handik_contacts` row (ambiguous many-per-phone matches are skipped). Idempotent (column guard + `contact_id IS NULL` clause).
* **Pre-approval customer picker (closes roadmap "Боль 5").** The "Add pre-approval" form on the Additional Forms preset edit screen now has a debounced customer search box. Typing queries the new `GET /admin/customers/search` endpoint; picking a result fills the hidden `contact_id` + the phone field. The operator can still type a phone manually for a customer not yet in the CRM. Editing the phone after a pick detaches the contact link (explicit override). Picker JS lives in `booking-app-admin.js::initApprovalPicker`.
* **New admin REST endpoint** `GET /admin/customers/search?q=&limit=&exclude_spam=` — thin wrapper over `Customer_View_Service::search()` (richer shape than the existing `/admin/contact/search`: `phone_display`, `is_returning`, `is_spam`, `last_seen`). Cap-gated by `admin_permission` (manage_options), nonce-verified.
* **`Form_Approvals_Service::create()`** gains a `$contact_id` argument. When a customer was picked, the contact's canonical phone overrides the typed value (prevents desync). When no contact was picked, the service backfills the link if the phone already matches a known contact. The admin add-approval handler forwards the picked `contact_id`.
* **Pre-approval list** gains a "Customer" column — linked to the Customer profile when the approval is tied to a contact, "Not in CRM" otherwise. Built on the Sprint 1 `Admin_Helpers::customer_link()`.
* **Bookings list — Source filter + column (closes roadmap "Боль 3" partially).** New "Source" filter dropdown (All / Main SPA / Direct form / Project form / External Cal) and a new "Source" column in the table view rendered as a colour-coded pill. Classification is row-only (FK columns) via the new static `Customer_View_Service::source_for_row()` — no extra queries. This subsumes the old Additional Forms Direct/Project sub-screens conceptually: those submissions are just bookings filtered by source. (Full menu reorg is Sprint 6.)
* **i18n** — one new admin key `approvalNoMatch`. Five new filter labels + column headers are translatable.
* No customer-facing change. Migration auto-runs on the next admin load.

= 2.1.31.0 =
* **Sprint 1 / Customer unification — Customer 360 read-model + cross-links.** First sprint of the customer-unification roadmap. Introduces a single source of truth for "resolve the customer + address for any booking" and makes customer names clickable across the admin. No DB change, no migration, no breaking change.
* **New service** `Handik_Booking_App_Customer_View_Service`. Composes the existing CRM services (contacts / addresses / job_requests / bookings) behind one read-model with a per-request instance cache:
  * `for_booking( $booking )` — resolves contact + address + source marker (`main` / `direct` / `project` / `external` / `external_unmatched`) plus the source-specific rows, for any of the four booking origins. Centralizes logic that used to be duplicated inline in the Bookings detail renderer.
  * `get( $contact_id )` — contact + addresses + primary address + requests + baseline stats (used end-to-end by the later Customers sprint).
  * `search( $query, $limit )` — name / phone / email autocomplete shape for admin pickers (consumed by the Sprint 2 pre-approval picker).
  * `profile_url( $contact_id )` — canonical Customer-profile deep-link.
* **P1 fix — external Cal bookings now show the customer's address.** External bookings carry `external_contact_id` but no `address_id`, so the Bookings detail page always rendered "No address" even though the linked contact had a primary address (People showed it fine). `for_booking()` now falls back to the contact's primary address from `handik_addresses` when the booking source has none. (Roadmap "Боль 1".)
* **Customer names are links across the admin.** New `Admin_Helpers::customer_link( $contact, $label = null )` renders the name as a link to the Customer profile (falls back to plain text for external/synthesized contacts with no real id). Wired into: Bookings detail "At a glance" Client cell, Additional Forms → Direct Submissions list, Additional Forms → Project Schedules list + project detail. (Roadmap "Боль 2".)
* **Refactor** `Admin_Bookings::render_detail()` to resolve contact + address + source rows through `Customer_View_Service::for_booking()` instead of its own inline per-source logic. Behavior is identical for main / direct / project bookings; external bookings gain the address fallback above.
* Wiring: the service is constructed once in the plugin container and threaded into the Admin page renderers (with a lazy fallback build so legacy construction keeps working). Dashboard next-visit rows already link to the booking detail (the row itself is the link), so no nested link was added there.
* No DB change. No new public REST endpoint. No customer-facing change.

= 2.1.30.1 =
* **P0 fix — Additional Forms phone OTP verification broken on 2.1.30.0.** Owner-reported: customers could not advance past the 6-digit OTP step; the JS console showed `ReferenceError: config is not defined`. Two new methods added in 2.1.30.0 (`approvalWarningMarkup`, `checkPresetApproval`) referenced a bare `config` identifier — but `booking-forms.js` exposes its config as an instance property (`this.config`, with shortcuts `this.preset` / `this.i18n`), not as a module-scope `const`. The bare reference was an undefined-variable crash on the first frame after `phone-verify/check` resolved, before the SPA could advance to `details`. Replaced with `this.preset.preset_slug` and `this.config.mainBookingUrl`. The new step + check function are now exercised on every OTP success path.
* **Observability — pre-approval gate is now visible in Logs view.** `POST /forms/preset/{slug}/check-approval` writes an info-level entry on every check: `Form pre-approval check.` with `preset_slug`, last-4 digits of the verified phone (PII-redacted), `active_count`, and the resolved `approved` flag. Pairs with the existing `Form approval created.` / `Form approval revoked.` / `Form approval consumed.` info entries the service emits on writes — so the operator can trace a full link → OTP → check → consume cycle in `Handik Booking → Logs` without touching the database.
* **Self-test for the gate.** New "Run self-test" button under each preset's Pre-approvals block (Admin → Additional Forms → Presets → preset). Clicking it calls `Form_Approvals_Service::self_test()` which exercises the full lifecycle against a synthetic slug (`__handik_self_test__`) and phone (`+15555550199`): create x2, count active for matched phone, count for other phone, consume one, count again, raw-vs-E.164 phone normalization parity, revoke remaining, idempotent revoke. All rows are wiped before and after so the test is hermetic and can't collide with real data. Results render as a green/red PASS/FAIL table inline under the block — verifies the schema migration, service, and DB writes after every upgrade in 30 seconds.
* No DB change. No new endpoint. No customer-facing UI copy change.

= 2.1.30.0 =
* **Additional Forms — soft phone pre-approval gate for direct booking links.** Owner workflow: Alex sends a customer a direct link to one specific preset (e.g. `https://handik.pro/booking/large-visit-360/`). He doesn't want the customer to re-use that link later for an unrelated job. Pre-2.1.30 the only options were "leave the link wide open" or "build a separate one-shot system". This release adds a soft-gate middle ground: operator pre-approves the customer's phone number for the preset; after the OTP step the form silently proceeds; ANY other phone number lands on a friendly "this wasn't pre-approved" screen that points to the main booking page. The customer can always click "Continue anyway" — this is operator visibility, not a hard block.
* **New table** `handik_form_approvals` (migration 1.6.2): one row per pre-approval. Columns: `preset_slug`, `phone` (E.164 normalized via `Contacts_Service::normalize_phone`), `notes` (operator memo, admin-only), `created_by`, `created_at`, `expires_at` (optional, NULL = forever until consumed), `consumed_at`, `consumed_booking_id`, `status` (`active` / `consumed` / `revoked` / `expired`). Composite key on `(preset_slug, phone, status)` so the per-booking lookup is index-friendly. Expired rows are auto-flipped to `expired` lazily inside `count_active_for_phone` / `consume_one_for_phone` — no separate cron pass.
* **New service** `Handik_Booking_App_Form_Approvals_Service`:
  * `create( preset_slug, phone, notes, expires_at )` — operator-side insert.
  * `revoke( id )` — operator-side soft-delete (status → `revoked`).
  * `count_active_for_phone( preset_slug, phone )` — drives the warning gate; returns the number of active approvals for the pair.
  * `consume_one_for_phone( preset_slug, phone, booking_id )` — atomic CAS-update consumes the OLDEST active row first; lost-race recurses once.
  * `list_filtered( [preset_slug?, status?] )` — backs the admin pre-approvals list.
* **New public REST endpoint** `POST /forms/preset/{slug}/check-approval`. Nonce + rate-limited; takes `verified_token` (the same HMAC-signed token issued by `/phone-verify/check`) and returns `{ approved: bool, active_count: int, gate_enabled: bool }`. The Forms SPA calls it after a successful OTP verify AND after the 30-day-cache `phone-verify/restore`. The verified_token is re-validated server-side via `Auth_Service::restore_verified_client`, so a caller can only check approvals for a phone they own.
* **New step** `approval-warning` in the Additional Forms SPA (`assets/booking-forms.js`). Routes to it from the OTP-success / restore-success branches when `check-approval` returns `approved: false`. Two CTAs: "Go to main booking form" (links to the new `forms_main_booking_url` setting, default `https://handik.pro/`) and "Continue anyway" (proceeds to `details`). State flag `approvalApproved` stays sticky once the customer has clicked through, so a re-render doesn't bounce back to the warning. Soft-fail policy: any network / token / 4xx error in the check resolves to `approved: true` and the customer proceeds silently — better booking through than blocking on a flaky network.
* **Admin UI** added to the per-preset edit screen under Additional Forms → Presets → (preset). New "Pre-approvals (soft phone gate)" block: form to add `phone` + optional `notes` + optional `expires_at` (datetime-local), plus a table of all approvals (`active` / `consumed` / `revoked` / `expired`) with a per-row Revoke button. Uses the same form-POST + nonce pattern as the existing `maybe_save_preset` handler — no new JS in the admin layer.
* **Consume hooks** wired into `Direct_Booking_Service::capture_booking()` and `Project_Schedule_Service::confirm_schedule()`. After the local row flips to BOOKED / CONFIRMED, the service calls `Form_Approvals_Service::consume_one_for_phone( preset_slug, contact_phone )`. No-op when the gate isn't wired or no active row matches (customer continued through the warning). Project bookings count as one appointment regardless of the N underlying work-day Cal bookings.
* **New setting** `forms_main_booking_url` (default `https://handik.pro/`). Editable in admin Settings → Cal.com section. Exposed to the Forms SPA via `config.mainBookingUrl`. The warning screen's primary CTA points here.
* **i18n** — five new keys: `approvalWarningTitle`, `approvalWarningIntro`, `approvalWarningBody`, `approvalWarningMainCta`, `approvalWarningContinueCta`. All translatable through the standard `__()` path.
* DB migration only — no breaking change to existing presets. Plugin behaves identically to 2.1.29.2 until the operator creates the first approval row for a preset.

= 2.1.29.2 =
* **P0 follow-up — 2.1.29.1's dedup fix in `handik-chatkit-bridge.js::saveStructuredResult` regressed on follow-up turns where the assistant produced a DIFFERENT structured payload from the previous turn.** Owner-reported with full server + bridge logs for request 217: turn 1 routes to a `standard_visit` (1-2 hours), CTA enables. Turn 2 the customer adds context, the agent re-classifies to `extended_visit` (3-5 hours) and calls `save_assistant_routing_result` with the new payload — but the bridge's "save completed" log surfaces the OLD `standard_visit` / `ready=true` values, the server-side `Assistant result received` log for the extended_visit payload is missing entirely, and the SPA stays on the previous turn's routing with the CTA stuck disabled (the gate read state.assistantReadyForBooking off of the stale payload).
* **Root cause — race + cache pollution.** ChatKit emits the agent's structured result through multiple channels in close succession (`chatkit.log`, `chatkit.effect`, `chatkit.message`, AND the `save_assistant_routing_result` client-tool dispatcher). Each one calls `saveStructuredResult( params, source )`. Whichever fires first sets `record.handledSignature` to the new (extended_visit) signature and starts the POST. The next concurrent call sees `handledSignature === signature_new`, falls into the dedup fast path, and 2.1.29.1's fix returned `record.lastStructuredPayload` — which is the previous turn's (standard_visit) payload because the in-flight POST hasn't resolved yet. The dedup caller (`save_assistant_routing_result` handler) then resolves its `then()` chain with that stale payload, logs `"ChatKit routing result tool save completed"` with the wrong values, and fires `onComplete( normalized_new, cached_old )`. The SPA's `applySavedAssistantRouting` merges with the old `routing` + `assistant_result` blocks winning the right-side of `mergeAssistantResult`, leaving local state on the previous turn's routing.
* **Fix 1 (`saveStructuredResult` chains onto in-flight POST):** new `record.pendingStructuredPromise` field tracks the in-flight POST for the current signature. When a concurrent call hits the dedup branch AND a POST is in flight, return the pending promise rather than synthesizing one from `lastStructuredPayload`. All concurrent callers now await the SAME fresh server response — single network round-trip (the dedup goal preserved), no stale-payload resolution. The pending field clears via a `.then( cleanup, cleanup )` callback whether the POST succeeded or failed; subsequent calls behave normally.
* **Fix 2 (composer.submit invalidates the structured-result cache):** in the bridge's `chatkit.log` event handler, the `composer.submit` branch now resets `record.handledSignature` and `record.lastStructuredPayload` BEFORE invoking `onComposerSubmit`. The dedup state is meaningful only within a single turn (same payload arriving via several ChatKit event channels) — cross-turn we always want the first `saveStructuredResult` call to round-trip, even if the agent ends up producing an identical signature. This guarantees the SPA's per-turn status block / CTA always reflects the current turn's server-side decision and a duplicate POST cannot resolve with last turn's payload.
* **Belt and suspenders:** the previous dedup branch's "fire onComplete with cached payload" path is preserved for the case where a POST has already resolved (`pendingStructuredPromise === null`) but the agent re-asserts the same structured result. That path is safe — by then `lastStructuredPayload` corresponds exactly to the signature we just matched on, and re-firing `onComplete` lets the SPA re-clear the per-turn status block without an unnecessary network round-trip.
* No DB change. No new endpoint. No setting touched. Front-end only — `assets/handik-chatkit-bridge.js`.

= 2.1.29.1 =
* **P0 fix — virtual-assistant status block stuck at the 50s stage on follow-up turns AND booking CTA stuck disabled after the second message.** Owner-reported on the 2.1.29.0 rollout: first user message worked (status block animated through the early stages, assistant replied, CTA enabled). Second user message: the status block ran all the way to the 50s "Open the booking page directly" fallback even though the assistant actually replied in the chat, and the booking CTA stayed disabled with the log line "Assistant continue blocked: assistant_ready_for_booking false". Two pre-existing bugs that the new prominent status block surfaced — both fixed in lockstep.
* **Fix 1 (`assets/booking-app.js` — `onMessageActivity`):** the "contains" checks for the message role were written as `false !== messageType.indexOf( 'user' )`. `String.prototype.indexOf` returns `-1` (not `false`) when the needle is missing, so `false !== -1` evaluates to `true` for every non-empty messageType — both `isUserLike` AND `isAssistantLike` were always `true`, the `if`/`else if` always picked the user branch, and assistant message events never made it into the assistant-like branch. Result: the per-turn status block never cleared on assistant tokens, and `assistantReadyForBooking` was never restored from message activity. Fix: use the canonical `-1 !== messageType.indexOf( ... )` "contains" pattern. Bug predates the 2.1.29.0 status-block rewrite — the old "Thinking…" pill just hid via `onComplete` so the misclassification was invisible until the new system relied on assistant-side message activity to keep the block in sync.
* **Fix 2 (`assets/handik-chatkit-bridge.js` — `saveStructuredResult`):** when the model returned the same `booking_type` / `duration_bucket` / pricing payload on a follow-up turn (the common case for clarification turns or "yes, please book" turns), the bridge dedup-fast-pathed on the JSON signature and returned early WITHOUT invoking `onComplete`. The booking-app SPA wires the per-turn status-block clear AND the `applySavedAssistantRouting` call (which is the only place `assistantReadyForBooking` gets set back to `true`) through `onComplete` — so dedup left both stuck. Fix: skip the server round-trip on a duplicate signature (it's pointless — the server already has this routing saved) but still invoke `onStructuredResult` + `onComplete` with the cached payload so the SPA can clear the status block and re-enable the CTA. Wrapped in try/catch + error-log so a buggy caller can never break the bridge's fast path.
* No DB change. No new endpoint. No setting touched. Front-end only — `assets/booking-app.js` + `assets/handik-chatkit-bridge.js`.

= 2.1.29.0 =
* **Virtual-assistant "thinking" UX.** Owner-reported: real assistant first-token latency lands in the 20–60s range, and the old "Thinking…" pill at the bottom-left of the chat host was too small to read on mobile and felt static enough that customers thought the page had frozen. Replaced with a single, prominent status block layered over the chat host that rotates its copy on a wall-clock timeline so the wait reads as in-progress, not broken.
* **One block, seven stages — not seven bubbles.** The block appears on `composer.submit` and on every detected user-like message activity event from the ChatKit bridge. Inside the SAME DOM node, the text updates at 1s ("Reviewing your request…"), 5s ("Checking the details, photos, pricing, and booking type…"), 10s ("Still working on it. The assistant is matching the job details to the right visit type."), 20s ("This is taking a little longer than usual. The tiny robot gears are still turning."), 30s ("Almost there. The assistant is preparing the time and cost recommendation."), 40s ("Still thinking. The robot has not given up, it is just being very careful."), and 50s ("This is taking too long. You can keep waiting, or open the booking page directly and Alex will review the details before the visit."). The 50s stage additionally reveals an "Open the booking page directly" pill that points at the direct Cal.com URL so the customer is never trapped. Tone is intentionally light without sounding broken.
* **Cleared the moment the assistant responds.** Same DOM block is removed (fades out, then unmounts) on assistant-like message activity, structured-result-stored, `onComplete`, and `onError`. Restart also tears it down. Internal `assistantStatusTimers` array collects every scheduled `setTimeout` so a clear-call cancels every pending stage in one pass — no orphan stage fires after the assistant has already answered.
* **No-leak guarantee.** None of these status strings ever leave the browser. They are not sent to OpenAI, not appended to the ChatKit thread, not recorded into `handik_messages`, and not posted to any REST endpoint. The block is DOM-only; the server doesn't know it exists.
* **Visual prominence.** Larger card with a 4px accent-coloured left border, a subtle box-shadow pulse on a 2.4s loop, three bouncing dots, accent-coloured CTA pill (only at 50s). Centered with a 560px max-width on desktop; full-bleed on screens narrower than 540px so mobile customers can't miss it. Respects `prefers-reduced-motion`: pulse + dot bounce disabled, the block still appears but stays still.
* **Retired** the standalone 30s `showAssistantStuckBanner('response-timeout')` path — its job is now the 50s stage of the new status block, which is in line with the longer P95 latencies we see in production. The mount-failure (`showAssistantStuckBanner('mount-failed')`) and the 14s prepare-timeout (`'preparing-timeout'`) banners remain — different concept ("the chat itself couldn't load") and still surface independently of the per-turn status block.
* No DB change. No new endpoint. No setting touched. No server-side code change in this release — front-end only (`assets/booking-app.js` + `assets/booking-app.css`).

= 2.1.28.1 =
* **Documentation refresh.** No code changes — the plugin behaves identically to 2.1.28.0 at runtime. Ships an updated developer/contributor documentation set so a fresh `git pull` or WordPress auto-update brings the new docs along:
  * **README.md** — rewritten as a short developer entry point. Quick-start, repo layout, conventions (versioning, branches, commits, migrations), and common contributor tasks (add endpoint, add column, cut release). Points at the deeper docs below instead of duplicating them.
  * **ARCHITECTURE.md** (new) — 400-line detailed module map. Top-level layer diagram, DI boot order, full REST API catalog (public + Additional Forms + admin, one-line description per route), main SPA flow + ChatKit assistant step, Additional Forms flows (direct + project), Cal.com lifecycle (creation, cancellation, reschedule, webhook idempotency, UID resolution priority), notifications pipeline (action handlers + try/catch wrappers + idempotency columns + ICS builder), admin areas table, DB schema + migration history through 1.6.1, background wp_cron jobs, settings + integrations.
  * **RELEASE_CHECKLIST.md** (new) — 10-step actionable checklist for cutting a release: version-bump matrix, three-file lockstep, changelog convention, branch merge, tag with `v` prefix, GitHub release asset naming (`handik-booking-app.zip` — the WordPress updater regex matches that exact filename), updater verification, smoke-test.
  * **readme.txt** — Description block reworked to cover the actual current feature surface (was still describing the early-Sprint single-page wizard with no mention of Additional Forms, Cal lifecycle, AI assistant, or the operator tools). Added Requirements + Upgrade Notice sections.
* No DB change. No new endpoint. No setting touched.

= 2.1.28.0 =
* **Sprint 18 / Part 2 — reschedule from the admin booking detail propagates to Cal.com and the customer's calendar.** Follow-up to 2.1.27.0 which wired cancel + delete through to Cal. Same architecture: the plugin is the source of truth for the operator's intent; Cal.com bridges to the customer's calendar via the standard `.ics` invite-update mechanism. POST a new start time to Cal, Cal sends an updated invite, Apple / Google / Outlook Calendar moves the event in place — no manual fixup needed on the customer's side.
* **New service method** `Cal_Api_Service::reschedule_booking($uid, $new_start_iso, $reason)`. POSTs to `/v2/bookings/{uid}/reschedule` with `start` (ISO 8601 with timezone offset) + optional `reschedulingReason`. Cal recomputes `end` server-side from the event-type's configured duration — same fixed-vs-variable rule as `create_booking`, so we never send `lengthInMinutes` here either. `Cal-Idempotency-Key` hash includes the new start so a legitimate "actually pick a different time" retry isn't collapsed into the previous attempt. Returns the normalized booking shape (uid / id / start / end / url / raw) so the caller can mirror the new times locally.
* **New REST endpoint** `POST /admin/booking/{id}/reschedule`. Admin-only (existing `admin_permission` gate). Accepts `new_start` (datetime-local string from the admin modal) + optional `reason`. Server converts the datetime-local value to ISO 8601 in the configured `cal_api_timezone` (default `America/New_York`) before posting to Cal. Refuses if the new time is in the past, if the booking has no resolvable Cal UID (admin-only / external bookings), or if Cal returns an error. On success, mirrors `start_time` + `end_time` + `raw_webhook_json` into the local `handik_bookings` row and clears any `admin_status_override` that was previously set to `cancelled` or `rescheduled` (so the row shows back up as live).
* **Admin UI — "Reschedule" button** on the Bookings detail actions bar, between "Note" and "Completed". Only rendered for FUTURE bookings (past ones are a data-quality red flag for reschedule; admin can edit the time via the catalog tool if really needed). Carries `data-current-start="YYYY-MM-DDTHH:MM"` in the org timezone so the modal can pre-fill the picker to the existing slot — operator only changes the bit they're moving.
* **Reschedule modal** in `booking-app-admin.js` (`openRescheduleModal`). Renders an `<input type="datetime-local">` (pre-filled with the current start, `min` set to "now") + a `<textarea>` for the optional reason. On submit posts to the new endpoint; on success toasts "Rescheduled. Customer's calendar invite was updated." and reloads the page after 800ms so the at-a-glance bar + when-text reflect the new time.
* **i18n** — eight new `HandikAdmin.i18n` keys: `rescheduleTitle`, `rescheduleBody`, `rescheduleNewStartLabel`, `rescheduleReasonLabel`, `rescheduleReasonPlaceholder`, `rescheduleCta`, `rescheduleDone`, `rescheduleFailed`.
* **Trailing-edge sync**: when Cal.com fires its own `BOOKING_RESCHEDULED` webhook back to us (which it always does on a successful reschedule API call), the existing `Webhook_Service::dispatch_to_standard` / `dispatch_direct` / `dispatch_project` path already wires through to `Bookings_Service` and `Notifications_Service::dispatch_for_cal_reschedule` / `dispatch_for_direct_reschedule` (Sprint 14c). So the customer gets both Cal's updated `.ics` invite AND our branded reschedule notification — and any local-side fields the synchronous mirror missed (project_work_days.start_iso, direct_booking_requests metadata) get backfilled by the webhook within seconds of Cal accepting our reschedule.
* No DB change. No migration.

= 2.1.27.0 =
* **Sprint 18 — unified booking lifecycle: cancel + delete in the plugin now propagate to Cal.com automatically.** Owner-reported: every test booking required manual cleanup in three places — local plugin, Cal.com dashboard, and the resulting Apple Calendar event. Now there is ONE source of truth: do it in the plugin, the rest follows.
* **How Cal.com → Apple Calendar / Google Calendar sync works** (background context for the architecture): when Cal creates a booking it sends an `.ics` invite email to the attendees + organizer. Apple Calendar / Google Calendar / Outlook receive that invite and add the event. When a booking is *cancelled* on Cal.com (via the API or the Cal dashboard), Cal sends a `METHOD:CANCEL` `.ics` update via email — the receiving calendar app picks that up and removes/marks the event as cancelled automatically. We don't need a direct integration with Apple Calendar — Cal is the bridge. The plugin just has to cancel on Cal, and the calendar invite handles the rest.
* **What changed (server side):**
  * New REST_API helper `extract_cal_uid_for_booking($booking)` — resolves the Cal.com booking UID for a local handik_bookings row by walking the four possible sources in priority order: (1) `project_work_days.cal_booking_uid`, (2) `direct_booking_requests.cal_booking_uid`, (3) `raw_webhook_json` parsed for `uid` / `bookingUid` (covers main-SPA Cal flow + external bookings), (4) `cal_booking_id` only if it doesn't look like a numeric id (defensive — older rows that picked Cal's numeric id over its uid).
  * New REST_API helper `cancel_on_cal_for_booking($booking, $reason)` — calls `Cal_Api_Service::cancel_booking($uid, $reason)` and returns a structured result (`success` / `skipped: 'no_cal_api' | 'no_uid'` / `error: '<cal message>'`). Wired into three places: `admin_booking_status` when the status transition is `→ cancelled`, `admin_booking_delete` (single-row danger-zone), and `admin_bookings_bulk_delete` (looped, with a single reason applied to all in the batch). Cal failures are logged but never block the local action — the operator's cleanup succeeds even if Cal is unreachable or the booking was already cancelled there.
  * REST response shape gains `cal_cancelled: bool`, `cal_skipped: bool`, and (for bulk) per-id `cal_errors: []` so the UI can surface what happened on the Cal side.
* **What changed (admin UI):**
  * The "Cancelled" action (admin Bookings detail) now opens a textarea modal asking for an optional cancellation reason. Reason is forwarded to Cal as the cancellation message → customer sees it in the cancellation email + the calendar invite update. Empty reason → server defaults to "Cancelled by admin".
  * The danger-zone "Delete this booking" action now also prompts for the reason after the type-to-confirm input. Modal body copy reworded: "The local row will be removed and the booking will be cancelled on Cal.com — the customer will get a cancel-notification email and the event will disappear from their calendar." (Previously said the Cal side was NOT cancelled — that's no longer true.)
  * Bulk-delete (Bookings list) gains a second modal after the type-to-confirm: optional reason applied to every booking in the batch. Prompt copy updated to set expectations about per-booking Cal cancellation.
  * Contact bulk-delete inherits the same behavior on the server side (each cascaded booking is Cal-cancelled before the local cascade deletes it); the user-facing modal hints at this in its body copy.
* **Reschedule** — out of scope for this release. Requires a date-and-time picker UI inside the booking-detail page + a `Cal_Api_Service::reschedule_booking()` method that hits `POST /v2/bookings/{uid}/reschedule`. Booked as a separate Sprint 18 / Part 2 because the picker UI is the bulk of the work.
* No DB change. No migration. No customer-facing flow change (their side: just receives a cancel email + their calendar updates).

= 2.1.26.7 =
* **Project Work Days form: loading spinner + async email dispatch.** Owner-reported (after 2.1.26.6 ended the wp_tempnam fatal): the Confirm-selected-days flow works, but the customer waits 4-9 seconds for "You're all set" to appear with no visual signal that the request is in flight. Two coordinated improvements:
* **Loading overlay** in the Forms SPA (`booking-forms.js::render`): when `state.busy === true` (set by every `api()` call — confirm, OTP verify, save-selection, etc.), an absolutely-positioned `.handik-booking-app__loading-overlay` is layered over the shell with the same `<div class="sp sp-loadbar">` spinner + "Loading" label the main `[handik_booking_app]` form has used since Sprint 1. Forms SPA inherits `booking-app.css`, so the spinner + overlay styles come for free — this is markup-only. The previous behaviour was "Continue button greys out, nothing else happens" — easy to misread as a frozen page.
* **Async email dispatch** for project bookings: `Project_Schedule_Service::confirm_schedule()` now schedules `Notifications_Service::dispatch_for_project()` via `wp_schedule_single_event( time(), 'handik_booking_app_dispatch_project_email', [ $schedule_id ] )` instead of calling it synchronously. The cron event fires on the next page request — typically the success-page asset load, within seconds on any active site — so the customer-confirm endpoint returns ~1-3 seconds faster (we no longer block on wp_mail + SMTP). Falls back to synchronous dispatch when `wp_schedule_single_event` returns false (cron disabled OR duplicate-event guard kicked in) so the email is never silently dropped. The 2.1.26.5 try/catch around the action handler is still in place — it's a separate safety net for throwables inside the dispatch chain regardless of which path (cron vs. sync) hits it.
* **i18n** — new `loadingLabel` key on the Forms SPA's localized strings (defaults to "Loading"). Audit: full repo scan confirms no Russian (or any non-English) text leaks into user-facing markup, settings defaults, or `__()` strings — the only Cyrillic-containing files are `class-direct-booking-service.php` (intentional input normalizer that replaces "США" → "USA" before sending the address to Cal) and developer comments. No customer-facing copy regressions.
* No DB change. No migration. No new REST endpoint.

= 2.1.26.6 =
* **P0 root-cause fix — Project Work Days form email-dispatch fatal that 2.1.26.5's try/catch caught.** 2.1.26.5 added a `try { ... } catch ( \Throwable $e )` around the action handler so the customer-confirm flow no longer 500s on email failure; the catch logged the specific throwable. Owner's next test surfaced it:
  ```
  message: "Call to undefined function wp_tempnam()"
  file:    "...class-notifications-service.php"
  line:    1782
  ```
* `wp_tempnam()` is defined in `wp-admin/includes/file.php`, which WordPress loads only for `/wp-admin` requests. REST API requests (and cron / front-end paths) don't auto-load it — calling the function there is a fatal. This is a long-standing WordPress gotcha for any plugin that writes temp files outside the admin context. The fatal was caught by 2.1.26.5's wrapper so the booking still succeeded, but the email + .ics attachment never went out.
* Fix: just-in-time `require_once ABSPATH . 'wp-admin/includes/file.php';` immediately before each `wp_tempnam()` call (guarded by `function_exists()` so it's a no-op on admin contexts where the file is already loaded, and on subsequent calls within the same request). Applied to both `write_ics_temp_file()` (regular confirmation) and `write_ics_temp_file_for_status()` (cancellation / reschedule). Customer + owner emails with the .ics calendar attachment now go through on project bookings.
* No DB change. No migration. No UI change. The 2.1.26.5 try/catch + diagnostic logging stays — it's the safety net that surfaced this fatal with file + line + message in 30 seconds instead of guessing for weeks. If any future throwable lands in the notification path it'll show up the same way.
* **Known limitation (not in this release):** the customer-confirm endpoint stays open for the full duration of `confirm_schedule` — N sequential Cal API calls (1-3s each typically, up to 12s timeout per call) + the wp_mail dispatch. For a 3-day project this is 4-12 seconds before the customer sees "You're all set." Async email dispatch (defer to wp_cron, return success immediately after the Cal API loop) is queued as a follow-up perf patch but ships separately so this P0 lands clean.

= 2.1.26.5 =
* **P0 fix — Project Work Days form: confirm STILL returns 500 after 2.1.26.4 even though Cal bookings get created and the schedule is marked confirmed.** Owner-reported with logs showing the "Project schedule confirmed" entry firing (so the work-day creation loop completed) but no error log captured before the WSOD critical-error page. The fatal happens between the "confirmed" log line and the success return — exactly where the synchronous customer-confirmation email dispatch fires (`Notifications_Service::dispatch_for_project($schedule_id)` → `do_action(handik_booking_confirmed)` → `handle_booking_confirmed` → template render + .ics build + `wp_mail`). Any throwable in that chain (template parse error, .ics builder edge case, wp_mail SMTP timeout, plugin host max_execution_time) takes down the booking-flow request that's still holding the HTTP connection open, even though the booking is already committed to the database.
* Fix: wrap every action handler (`handle_booking_confirmed`, `handle_booking_cancelled`, `handle_booking_rescheduled`) in a try/catch closure registered as the actual `add_action` callback. Throwables are caught by a new shared `log_handler_throwable()` helper that records the action name + the throwable's message + file + line + a slim slice of the context (source / request_id / booking_id / idempotency table+row). Booking flow continues — the user gets a success response, and email failures become loggable problems rather than user-visible 500s.
* Belt-and-suspenders: the `confirm_schedule()` callsite of `dispatch_for_project()` is also wrapped in try/catch (covers throws DURING context build, before `do_action` even fires).
* Diagnostic breadcrumb: `dispatch_for_project()` now logs an info entry "Project email dispatch starting." right after entering the method. Pairing this with the existing "Project schedule confirmed." entry tells us whether the dispatch even reached its body. The next time this fatals (if at all), the throwable's file + line will be in the error log, and we can fix the root cause precisely instead of guessing.
* No DB change. No migration. No user-visible UI change. Defensive-only patch — the next test should either succeed silently OR surface a specific error log entry that names the broken file.

= 2.1.26.4 =
* **P0 fix — Project Work Days form confirm now succeeds on the first tap.** 2.1.26.3 introduced a retry-with-`lengthInMinutes` path to handle Cal v2's mutually-exclusive length rules (fixed-length event types REJECT it, variable-length event types REQUIRE it). The worst-case retry path doubled the time per day to 24s (12s Cal HTTP timeout × 2 attempts) and on multi-day project schedules the cumulative request time exceeded PHP `max_execution_time` on the owner's environment, producing a WSOD fatal on the first confirm tap. The SECOND tap then completed instantly because Cal-Idempotency-Key replayed the cached partial-attempts from the first run. Owner observed "long thinking, error, second tap success" + browser-side 500 with WP critical-error page.
* Fix: remove the retry entirely. `Cal_Api_Service::create_booking()` sends neither `end` nor `lengthInMinutes` — Cal v2 derives the end-time server-side from `start + event-type-configured-duration`. Works for FIXED-length event types (the common case + the owner's setup). VARIABLE-length event types would 400 here; that's a future-feature gate (pre-fetch event-type metadata, conditionally include `lengthInMinutes`) and ships separately if/when a preset actually needs it.
* **Critical data-loss fix — bulk-delete on the Bookings list.** Owner-reported: "I clicked Select all, then manually unchecked the 15 I wanted to keep, clicked Delete — and EVERY booking got erased including the 15 unchecked ones." Root cause: the Bookings list renders TWO checkboxes per row — one in the cards view, one in the table view — both with the same `value=<id>`. Toggle-all set BOTH copies of every row to checked. Then a manual uncheck only flipped the ONE copy the user clicked (the cards-view one on mobile); the table-view duplicate stayed checked. The Delete handler collected every `:checked` checkbox without dedup-by-value, so the table-view copies of the unchecked-in-cards-view ids ended up in the payload, and the server cascade-deleted them too.
* Fix in `initBulkMode()` (booking-app-admin.js): on any per-row checkbox change, sync ALL `[data-handik-bulk-row][value="<that id>"]` siblings to the same state. The visible state in cards/table stays consistent regardless of which view the user interacted with. Counts + apply payload now use a `Set` of unique ids, deduping any double-counted duplicates as a belt-and-suspenders.
* **Bulk-delete safety — type-to-confirm modal.** Even with the dedup fix above, an accidental Select-all could still wipe everything in one tap. Same pattern the single-row danger-zone delete already uses: confirmation modal shows "About to delete 47 of 200 bookings... Type DELETE 47 to confirm" and the request only fires if the typed text matches exactly. Cancel or Esc returns null and aborts silently; a typed-but-wrong value toasts "Confirmation text did not match. Nothing deleted." Applied to both Bookings and Contacts bulk-delete.
* **Server-side audit log** on `POST /admin/bookings/bulk-delete` and `POST /admin/contacts/bulk-delete` — every call logs the user_id + incoming id list + final deleted/failed counts via `Handik_Logger::warning` (incoming) + `::info` (completed). If any future "I deleted X but Y went missing too" report comes in, the log has the exact id payload the server received from the client so we can prove whether the bug was JS-side or server-side.
* **`openModal()` helper** in `booking-app-admin.js` gains a new `input: true` option for single-line text input (in addition to the existing `textarea: true`). The new bulk-delete prompt uses it; future type-to-confirm flows can reuse the same shape.
* Five new HandikAdmin.i18n strings: `bulkDeleteBookingsPrompt`, `bulkDeleteContactsPrompt`, `bulkDeleteMismatch`.
* No DB change. No migration.

= 2.1.26.3 =
* **P0 fix — Project Work Days form still failing after 2.1.26.2.** Owner-reported with server log: `Can't specify 'lengthInMinutes' because event type does not have multiple possible lengths. Please, remove the 'lengthInMinutes' field from the request.` Cal v2 has two mutually-exclusive length rules — FIXED-length event types REJECT `lengthInMinutes`, VARIABLE-length event types REQUIRE it. 2.1.26.2 removed the forbidden `end` field but always sent `lengthInMinutes`, which then broke the fixed-length case (the owner's setup). Fix: try the POST WITHOUT `lengthInMinutes` first (matches the common fixed-length path — Cal derives the end from the event-type config). If Cal returns a 400 mentioning `lengthInMinutes` is needed, retry once WITH the computed length value, bumping the `Cal-Idempotency-Key` suffix so Cal doesn't serve the cached 400. Direct booking form remains unaffected (uses the iframe, not server-side `create_booking`).
* **Pull from Cal.com defaults to upcoming-only.** Owner-reported: the previous build pulled every Cal booking including past cancellations from old test bookings, flooding the admin Bookings list. Now the REST endpoint defaults to `status=upcoming` on the Cal `/v2/bookings` query (admin can override via `status` body param if they ever need to backfill cancelled or past rows for audit). Also belt-and-suspenders post-fetch: if Cal still returns a row with `payload.status === 'cancelled'` (can happen when a booking was cancelled AFTER its slot entered the upcoming window), the handler skips that row rather than upserting it as `booked`.
* **Bulk-delete on Bookings list.** Owner-requested cleanup-tool: now they have ~hundreds of test bookings from the pull-from-cal backfill that need clearing. New REST endpoint `POST /admin/bookings/bulk-delete`. UI: a "Select" toggle button in the page CTA row enables bulk mode — checkboxes appear over each card (cards view) and as a new leading column (table view); a thin single-line bulk-bar gains a Select-all checkbox + "N selected" count + "Delete selected" button. Per the owner's "пожалуйста не занимай много места на экране" — the bar is hidden by default, only revealed when the operator opts into bulk mode by clicking "Select". Capped at 200 per call.
* **Bulk-delete on People & Requests list.** Same pattern as Bookings — toggle in CTA, checkboxes overlay each contact card, compact bulk-bar reveals on demand. New REST endpoint `POST /admin/contacts/bulk-delete`. Reuses the single-row cascade (`Cascade_Delete_Service::delete_contact`) so each drop removes the contact + all their requests + bookings + addresses + photos + messages. Typed-confirm modal explicitly enumerates what's about to be wiped. Capped at 100 per call (contact cascade is much heavier than booking delete).
* **Drafts consolidation.** Owner-reported: People & Requests had a "Drafts only" chip routing to a CONTACTS-list filtered by drafts, while the Dashboard "drafts" chip went to a REQUEST-FOCUS-LIST of abandoned drafts — two different views for the same concept. Now both chips route to `?filter=drafts_old`, the focus list. One consistent definition of "Drafts" reachable from either entry point.
* **JS handler** — new `initBulkMode()` in `booking-app-admin.js` (generic — any container with `data-handik-bulk-section` participates). Toggle button via `[data-handik-bulk-toggle][data-handik-bulk-target=".css-selector"]` flips `is-bulk-mode` class on the section. Select-all ↔ row checkboxes with indeterminate state for partial selection. Apply button posts ids to the section's `data-bulk-endpoint`, toasts the count, reloads on success. Six new i18n strings: `bulkSelect`, `bulkDone`, `bulkDeleteBookingsConfirm`, `bulkDeleteBookingsDone`, `bulkDeleteContactsConfirm`, `bulkDeleteContactsDone`.
* No DB change. No migration.
* Out of scope (queued as follow-up): calendar-style multi-day picker on the Project Work Days form. Owner asked for the rows-of-days picker to be replaced with a Cal.com-style calendar view; that's a bigger UX rewrite (~200 LOC across markup + JS state machine + slot rendering) and ships separately so this P0 + cleanup-tools release can roll out independently.

= 2.1.26.2 =
* **P0 fix — Project Work Days form: multi-day confirm fails with "One of your selected days could not be confirmed and the others were released. Please pick a new set."** Owner-reported after 2.1.26.1. Investigated the server-side log: Cal.com API v2 responds `end property is wrong, property end should not exist` when our `Cal_Api_Service::create_booking()` POSTs a body containing both `start` + `end` + `lengthInMinutes`. Cal's bookings v2 schema dropped acceptance of `end` — it now derives it from `start + lengthInMinutes`. We had been sending all three since 2.1.9.x; the schema tightening on Cal's side started rejecting bookings sometime between 2.1.26.0 and the owner's most recent test. Fix: strip `end` from the request body and derive `lengthInMinutes` from `args['end'] - args['start']` if the caller passed `end` without `duration_minutes`. The body the API actually sees now is `{start, lengthInMinutes, attendee, eventTypeId, ...}`. Direct booking form was unaffected because it uses the Cal embed iframe, not our server-side `create_booking`.
* **Pull from Cal.com — owner-requested backfill button.** Owner-reported gap: bookings made BEFORE we shipped webhook-side external mirroring (2.1.24.0) live on Cal but never reached our `handik_bookings` table — they don't appear in the unified admin Bookings list. Also covers webhook-drop scenarios (network blip, secret rotated mid-flight). New REST endpoint `POST /handik-booking-app/v1/admin/bookings/pull-from-cal` admin-only: lists Cal bookings via the v2 API (new `Cal_Api_Service::list_bookings($args)` method, paginated, capped at 1000 per call), reads the existing `handik_bookings.cal_booking_id` set into a hash, and calls `upsert_external_booking` only for items missing locally. Reuses the same external-booking code path the webhook uses — attendee → contact matching, raw_webhook_json stash, external-row fallback — so the result is identical to a freshly-arrived webhook. Default range: last 90 days through 90 days in the future; admin can pass `dateFrom` / `dateTo` body params or `pull_all=1` to override.
* **Admin UI** — "Pull from Cal.com" button on the Bookings list page next to the "+ Add booking" button (`data-handik-pull-from-cal`). Click → toast `Fetched X · Y new · Z already there` → page reloads if any rows were inserted. Three new i18n strings on `HandikAdmin.i18n`: `pullFromCalFetching`, `pullFromCalDone`, `pullFromCalFailed`.
* **Polish — shorter action button labels** on the Bookings detail page: `Add note` → `Note`, `Mark as completed` → `Completed`, `Mark as cancelled` → `Cancelled`. Owner-reported (cluster 2 followup) that even after the mobile-compact CSS in 2.1.25.0 the buttons still felt over-wide on phones.
* **Polish — "Abandoned drafts (24h+)" → "Drafts".** Owner-reported (cluster 3 followup): the dashboard "Action needed" chip and the focus-list page title both used the longer label. Renamed to just "Drafts" — the 24-hour cutoff is an implementation detail the operator doesn't need on the chip surface.
* No DB change. No migration.

= 2.1.26.1 =
* **A3 P0 fix — "Continue button sometimes doesn't fire on first tap" on mobile.** Owner-reported across both the main `[handik_booking_app]` form and the Additional Forms SPAs. Two coordinated patches close the race:
* **Root cause** is a synchronous re-render driven by the field blur handler. When the customer taps Continue on a phone, the previously-focused input fires `blur` BEFORE the tap's `click` event reaches the button. The blur handlers in both SPAs called `this.render()` synchronously to refresh the inline error spans for the just-blurred field — but `render()` does `shell.innerHTML = '...'`, which destroys the Continue button DOM node the touch was about to land on. The queued `click` event then dispatches against a detached element (whose listener went with it when innerHTML reassigned) and silently no-ops. From the customer's perspective: tap, nothing happens, second tap works.
* **Fix #1 — defer the blur-driven re-render via `requestAnimationFrame`** in both `booking-forms.js::onBlur` (the catch-all "re-render on details/phone/otp step blur" branch) and `booking-app.js`'s three field-specific blur listeners (contact.full_name / contact.phone / contact.email). The click event drains first, the action handler runs (which renders itself on the resulting state change), and the blur-driven refresh ends up a no-op for the common "Continue after editing a field" path. Visible behaviour preserved — error spans still clear when the field becomes valid because the action handler's render captures the same state.
* **Fix #2 — add `touch-action: manipulation`** to `.handik-btn` in `booking-app.css` (shared between both SPAs). Eliminates the legacy 300ms tap delay some browsers still impose to detect double-tap-to-zoom — the form is viewport-sized on phones so the delay is pure latency. Belt-and-suspenders for the rAF defer above.
* No DB change, no REST changes, no schema. Three files: `booking-forms.js`, `booking-app.js`, `booking-app.css`.

= 2.1.26.0 =
* **Sprint 17 — People & Requests tweaks (cluster 3).** Two targeted improvements that owner asked for after the cluster 2 mobile pass shipped.
* **A2 — Apple Maps icon next to each saved address.** Owner-reported: opening a customer's saved address required either copying the text and switching to Maps, or relying on the at-a-glance Apple Maps button in the booking detail (which only works once a booking is on the calendar). Now every address row in `person_addresses_markup` carries an inline orange map-pin icon button that opens the full address (street + city + state + zip — unit is intentionally omitted because Apple Maps' geocoder doesn't parse "Apt 3B" reliably) in the native Apple Maps app on iOS / Safari, or maps.apple.com on Android / desktop. Reuses the `.handik-admin-icon-btn.is-map` style shipped in 2.1.25.0 with a new `--inline` size modifier (28×28 instead of 40×40) so the icon fits beside the address text without dwarfing it.
* **A4 — bulk-delete drafts in People & Requests.** Owner-reported: after deleting a batch of test contacts, the admin People & Requests page still showed dozens of "Unknown Drafts" (orphaned `handik_job_requests` rows with `contact_id=0` and an early `app_step`) that could only be cleared one-by-one through each row's detail page. Now: the "Abandoned drafts (24h+)" focus list (filter `?filter=drafts_old`) renders a bulk-action toolbar at the top with a "Select all" checkbox + a per-row checkbox + a "Delete selected" button + a live "N selected" count. Submits the collected IDs (defensively capped at 200 per call) to a new REST endpoint `POST /admin/job-requests/bulk-delete`, which loops each ID through the same `Cascade_Delete_Service::delete_request` path the single-row delete uses (so messages + bookings + photos + the request row all cascade identically). Response shape: `{ requested, deleted, failed }` so the JS can toast the count + flag any rows that couldn't be cleared. Gated on the existing `MANAGE_DELETE` capability — read-only admins don't see the bulk controls.
* **JS handler** `initBulkDeleteDrafts()` in `booking-app-admin.js`. Wires "Select all" ↔ row checkboxes (with an indeterminate state when a partial selection is active), enables/disables the Delete button live, prompts via the standard `openModal()` typed-confirm, posts to the endpoint, toasts the count, reloads the page on success. Five new i18n strings on `HandikAdmin.i18n`: `selected`, `bulkDeleteTitle`, `bulkDeleteConfirm`, `bulkDeleteDone`.
* **No DB change. No new schema. No data migration.** Adds one REST endpoint + one helper-method size icon-btn variant.

= 2.1.25.0 =
* **Sprint 16 — admin mobile UX pass (cluster 2).** Owner-reported: the admin Bookings detail page ate the top ~half of viewport on phones (sticky bar + big "Add note / Mark completed / Mark cancelled" buttons + tall at-a-glance card), Bookings list filters stayed permanently expanded eating another ~30% of viewport, and People & Requests person header repeated the same mistake with wide text buttons. None of that was tappable one-handed on a job site. Five coordinated changes ship together:
* **B1 + B3 — Bookings detail header**. `Admin_Bookings::sticky_action_bar_markup` rebuilt: no longer position-sticky (scrolls with the page like a normal header), no longer renders the phone number inline, no longer shows "Apple Maps" / "Cal.com" text labels. The "📞 +1 617 555 0123" pill / "🗺️ Apple Maps" pill / "📅 Cal.com" pill are replaced with a row of four 40×40 circular icon-only tap targets: green call (`tel:`), blue SMS (`sms:` — newly added `Admin_Helpers::sms_url`), orange Apple Maps, blue Cal.com. Each carries an `aria-label` + `title` containing the underlying phone/email/event for screen readers and hover tooltips. The number / email + the "is this the right customer?" mental check now live in the at-a-glance Client cell below the header.
* **B2 — Bookings detail action buttons**. The `Add note` / `Mark as completed` / `Mark as cancelled` / `Clear manual status` buttons + the "Current status:" pill row used to render at WP's default `.button` size + ~36px tall + generous horizontal padding, so all of them collectively pushed onto two rows on phones. CSS-only patch under `@media (max-width: 767px)`: 4×9px padding, 0.82rem text, min-height 32px, current-status pill drops to its own line below the buttons. Markup is unchanged so the action handlers (notes modal, status patch via `/admin/booking/{id}/status`) keep working byte-for-byte.
* **B4 — Bookings list filters**. The `filter_bar_markup` block (Time select + Status select + search + Apply / Reset) is now wrapped in a `<details class="handik-admin-filter-collapse">` — same class the Logs page already used — so the chrome is collapsed by default. **Auto-opens when any filter or search is active** (e.g. `?filter_time=this_week&q=zinkin` → summary reads "Filters · 2 active" and starts expanded so the operator sees what's constraining the list). Default state is closed → ~140px of viewport returns to the row list.
* **B5 — People & Requests header + list toolbar**. Person detail header rebuilt the same way as B1/B3: 📞 phone + ✉ email + 📅 Book-a-visit buttons (which printed the full phone/email text inline) are replaced with the matching `.handik-admin-icon-btn` row — call (green) / SMS (blue) / email (slate) / book-a-visit (primary blue). The h2 person name shrinks to 1.1rem and ellipsises on overflow so the row stays one line. List page toolbar + filter chips tighten margins on mobile, and the chips row now horizontally scrolls when there are more chips than fit (instead of wrapping onto a second row).
* **New helper** `Admin_Helpers::sms_url($phone)`. Strips formatting the same way `tel_url` does (digits + leading +) and returns `sms:+...` so the SMS icon opens the Messages composer pre-filled on iOS / the default messaging app on Android.
* **New shared CSS component** `.handik-admin-icon-btn` (with `.is-call` / `.is-sms` / `.is-map` / `.is-email` / `.is-book` / `.is-cal` modifiers). Reused by Bookings detail header AND Person detail header — single source of truth for the icon-only action buttons. Sized 40×40 desktop / 36×36 below 480px, SVG sized to match. Color-coded per the native iOS dialer / Messages / Maps expectation.
* **No DB change. No new REST endpoints. No JS changes.** Only PHP markup + CSS + one tiny helper method.
* Out of scope here (separately tracked): A2 (Apple Maps icon next to address in People & Requests), A3 (Continue button intermittent tap on mobile forms), A4 (bulk-select + delete drafts).

= 2.1.24.0 =
* **Sprint 15 / Part 3 — external Cal.com bookings now surface in the admin Bookings list.** Owner-reported scenario: customer can't complete the booking flow through the plugin (form error, embed timeout, etc.) → clicks the "Open the booking page directly" fallback link → books on Cal.com on Cal's own page → that booking previously vanished entirely from the plugin's admin. Cal's webhook fires, but our `Webhook_Service::handle_cal_webhook` routing requires either a `handik_*` metadata key, a `cal_booking_id` match against an existing `job_requests` row, or a pending-request email/phone fallback. None of those match for a booking made directly on Cal, so the handler logged an error and returned 404.
* **Schema** (`handik_bookings`, migration 1.6.1): new nullable `external_contact_id BIGINT(20) UNSIGNED NULL` + key, AFTER `project_work_day_id`. Optional FK into `handik_contacts` for when the webhook attendee email/phone resolves to a contact we already have on file. Idempotent via `column_exists`/`index_exists`. Now four mutually-exclusive sources can hang off a `handik_bookings` row:
  * `job_request_id IS NOT NULL`       → main SPA AI flow
  * `direct_request_id IS NOT NULL`    → public direct booking form (Sprint 4)
  * `project_work_day_id IS NOT NULL`  → public project work days form (Sprint 15 / Part 1, this release line)
  * `external_contact_id IS NOT NULL`  → external Cal.com booking matched to an existing contact (this release)
  * **all FKs NULL** → external Cal.com booking from an unknown attendee, with attendee info pulled from `raw_webhook_json` at render time
* **New service method** `Bookings_Service::upsert_external_booking($payload, $status)`. Flattens the payload through the existing `flatten_cal_embed_payload` helper (so nested `payload.booking.*` / `payload.eventType.*` shapes work), extracts the Cal booking id, tries `Contacts_Service::find_by_email_or_phone($attendee_email, $attendee_phone)` to populate `external_contact_id`, and INSERTs/UPDATEs the row with `cal_booking_id` UNIQUE-key idempotency. **We never auto-create a contact** — false-positive risk from test bookings is too high; the operator can manually link from the booking detail view later. Two new attendee-extraction helpers (`extract_attendee_email`, `extract_attendee_phone`) handle the half-dozen places Cal stashes contact info in the webhook payload across event types (`attendees[]`, `responses.email.value`, `attendeeEmail`, `smsReminderNumber`, etc.).
* **Wired into `Webhook_Service::handle_cal_webhook`**: the previous 404 fail path on `! $request_id` after all matching attempts is replaced with a call to `upsert_external_booking`. Cal stops retrying (the handler returns 200 with `{success: true, status, external: true}`), and the booking shows up in the admin. Same `map_status` rule for ignoring non-state-changing events still applies.
* **Plugin DI**: `Bookings_Service::__construct` gains optional `$contacts = null` arg. `class-plugin.php` passes `$this->contacts` (which is constructed earlier in the boot order, so DI order is fine). Null-default keeps legacy construction sites (tests, admin modules) working.
* **Admin Bookings — list page** (`Admin_Bookings::decorate_bookings` + cards + table):
  * Bulk-fetch any `external_contact_id` values into the existing contacts bulk fetch, so external bookings with a matched contact resolve cleanly with one query.
  * Per-row decoration loop gains a 4th branch (matched-contact case) and an unmatched-external case (all FKs NULL, contact stays null, render falls through to JSON extraction at card render time).
  * Cards + table extract attendee name/email out of `raw_webhook_json` when no contact match — so the Client column shows the actual person's name rather than "Unknown", even for bookings we can't link.
  * Task summary for external rows: `External Cal.com booking — <event_type_slug>` (with a translator hint) so the operator can tell at a glance which event type was booked and that it didn't come through the form.
* **Admin Bookings — booking detail page**: new `external_preset_block_markup()` renders an "External Cal.com booking" block with the attendee name/email/phone (best-effort, pulled from `raw_webhook_json`), the Cal event type slug, duration, and a "Linked contact" line indicating whether email/phone match attached this row to a person in People & Requests. Includes a muted note reminding the operator that address + job-scope are typically missing for external bookings and need a manual follow-up.
* **Admin Bookings — booking detail page**: ALSO adds a `project_preset_block_markup()` for project work-day rows. Was a 2.1.23.0 follow-up gap — the cards/table list rendered project rows but the detail page didn't have a content block, so it was empty between the at-a-glance bar and the technical block.
* **No JS changes.** No new REST endpoints. The webhook receiver already existed; we just stopped rejecting an unmatched route.

= 2.1.23.1 =
* **Sprint 15 / Part 2 — admin "What the customer wrote" panel: structured fallback + on-demand backfill from OpenAI.** Owner-reported: the panel was empty for newer main-SPA bookings even when the conversation clearly happened in ChatKit. Root cause is event-shape drift in the embedded `<openai-chatkit>` web component — the local JS bridge (`handik-chatkit-bridge.js`) listens for `chatkit.log` (`name=composer.submit`) and the bubbled `'message'` event, then runs `extractMessageText()` against the payload. Newer ChatKit releases occasionally ship event-payload structures where neither `detail.data.content` nor `detail.content` carries the typed string, so `recordMessage()` silently no-ops and the local `handik_messages` table stays empty for the booking. OpenAI's thread storage is always authoritative — this release adds a path to read it back.
* New `Handik_Booking_App_ChatKit_Service::fetch_thread_messages($thread_id, $limit)`. GETs `{api_base}/v1/chatkit/threads/{thread_id}/items` with the existing ChatKit auth headers (`Authorization: Bearer`, `OpenAI-Beta: chatkit_beta=v1`, `OpenAI-Project`, `OpenAI-Organization`). Pages through with `after=last_id` until `has_more=false` or 10 pages × 100 items = 1000-message defensive cap. `normalize_thread_item()` collapses each item's `content` parts (string OR array of `{type, text}` segments) into a single body string, infers `role` from the item `type` when it isn't set explicitly (`user_message` → user, `assistant_message` → assistant, etc.), and returns one row per renderable item — non-renderable tool calls / empty parts are dropped. Errors are logged with the OpenAI `x-request-id` header so admin can correlate against the OpenAI dashboard.
* New admin REST endpoint `POST /handik-booking-app/v1/admin/booking/{id}/fetch-chat`. Capability-gated via the existing `admin_permission` callback. Looks up `job_request_id`, refuses cleanly for direct/project-form bookings (400 with a clear message — those flows have no chat by design), then calls `ChatKit_Service::fetch_thread_messages` and feeds the result into `Messages_Service::record()`. **Custom dedup**: the built-in 10-second `record()` dedup window can't see bridge-captured rows from minutes/hours ago, so the handler pre-loads `list_for_request` and builds a `role|md5(content)` hash set to skip any message the bridge already captured. Response: `{ fetched, inserted, duplicates, openai_request_id }`.
* Admin booking detail "What the customer wrote" panel (`Admin_Bookings::transcript_block_markup`): when the local transcript is empty AND `chat_thread_id` is set, the panel renders a **structured fallback** of what we DO know — the initial customer-typed `short_description` (if present), the catalog tasks they selected, the assistant's `assistant_summary` — followed by a **"Load chat from OpenAI"** button. When the transcript already has rows, the same button surfaces as a smaller "Refresh from OpenAI" link so admin can pull any newer messages from the OpenAI side.
* JS handler `initFetchChat()` in `booking-app-admin.js`. Hits the new endpoint, surfaces the count via toast (`Fetched %1$d messages (%2$d new).`), and reloads the page on success so the transcript renders. Failure paths show the server-side error message inline + as a toast — including the "no chat for form booking" case.
* Four new i18n strings on `HandikAdmin.i18n`: `fetchingChat`, `fetchedChat`, `noChatFound`, `fetchFailed`.
* No schema change. No data migration. Plugin DB version stays at 1.6.0.
* Notes: the `/v1/chatkit/threads/{id}/items` endpoint URL + paging fields (`has_more`, `last_id`, `next_cursor`) are the documented OpenAI ChatKit beta shapes as of this release. The shipped code handles both `data[]` and `items[]` response containers and both `last_id` and `next_cursor` for paging. If OpenAI ships a breaking change, the error path logs the response body so we can adapt in a follow-up patch.

= 2.1.23.0 =
* **Sprint 15 / Part 1 — booking pipeline: Additional Forms bookings now surface in the admin Bookings list.** Owner-reported P0: every booking made through `[handik_project_day_form]` (multi-day projects) AND a subset of bookings made through `[handik_direct_booking_form]` (direct single-slot) saved the customer into People & Requests but never appeared in the unified Bookings list. Two distinct root causes, both fixed here.
* **Cause #1 (project work days — confirmed bug):** `Project_Schedule_Service::confirm_schedule` created N Cal.com bookings (one per work day), updated `handik_project_work_days` with their `cal_booking_id` / `cal_booking_uid`, sent the confirmation email — and stopped. There was no bridge from `handik_project_work_days` into the canonical `handik_bookings` table that the admin Bookings list (`?page=handik-booking-app-bookings`) reads from. The matching webhook handler `Webhook_Service::dispatch_project` only updated day status. Sprint 4 launched the project-day form with this gap; nobody caught it because the admin-side "Additional Forms" sub-screen showed schedules separately, hiding the missing mirror.
* **Cause #2 (direct form — schema drift):** `Direct_Booking_Service::capture_booking` *does* call `Bookings_Service::upsert_from_direct_capture` from the leading-edge iframe `bookingSuccessful` event (wired in 1.5.0). But the booking-id extraction read top-level keys (`$payload['id']`, `$payload['uid']`, etc.), which matched Cal's older flat embed shape but stopped finding the IDs once Cal moved to the modern nested shape (`$payload['booking']['id']` / `$payload['booking']['uid']`, with the event type slug under `$payload['eventType']['slug']`). Result: `extract_booking_id` returned `''`, the upsert bailed at the defensive "no Cal booking id yet — will retry on webhook" guard, the webhook arrived with an empty `cal_booking_id` already saved on the local row, the webhook-side `find_by_cal_booking_id` returned nothing, and the `handik_bookings` mirror was never written.
* **Schema** (`handik_bookings`, migration 1.6.0): new nullable `project_work_day_id BIGINT(20) UNSIGNED NULL` + key, paralleling the 1.5.0 `direct_request_id` column. Idempotent via `column_exists`/`index_exists`. Both columns can be NULL on the same row — the render code branches on whichever id is set:
  * `job_request_id IS NOT NULL`       → main SPA, JOIN `job_requests`
  * `direct_request_id IS NOT NULL`    → direct form, JOIN `direct_booking_requests`
  * `project_work_day_id IS NOT NULL`  → project form, JOIN `project_work_days` → `project_scheduling_requests`
* **New service method** `Bookings_Service::upsert_from_project($work_day_id, $payload, $status, $context)`. Mirrors `upsert_from_direct_capture` shape — same `cal_booking_id` UNIQUE-key idempotency — but keyed on `project_work_day_id`. Called from two places: (1) leading edge in `Project_Schedule_Service::confirm_schedule` right after each successful `Cal_Api_Service::create_booking`, with the normalized booking + the schedule's `booking_type`/`event_type_slug`/`work_day_duration_minutes` passed through as `$context`; (2) trailing edge in `Webhook_Service::dispatch_project` after `update_day_status_by_uid`, looking up the day_id via `Project_Schedule_Service::find_day_by_uid($uid)`. Per-day notifications are deliberately NOT dispatched — the project flow already sends one email per schedule (with one .ics carrying all N VEVENTs) in `confirm_schedule`, so per-day emails would spam.
* **New shared helper** `Bookings_Service::flatten_cal_embed_payload($payload)`. Hoists `$payload['booking']['*']` to top-level (additive — never overwrites a key already at the top, so older flat-shape payloads and the Cal webhook payload, which is pre-flat, become no-ops). Also hoists `$payload['eventType']['slug']` to `eventTypeSlug`. Called by `extract_booking_id`, `upsert_from_direct_capture`, and `upsert_from_project`, so every entry point reads top-level keys safely regardless of which Cal schema version delivered the event.
* **Admin Bookings — list page** (`Admin_Bookings::decorate_bookings`): bulk-fetch `project_work_days` for any rows with `project_work_day_id` set, then bulk-fetch their parent `project_scheduling_requests`, and feed those into the existing contact/address bulk fetch. The per-row decoration bundle gains two new keys (`project_day`, `project_schedule`) alongside the existing `request` / `direct_request`. Both the cards view and the table view render the third source.
* **Admin Bookings — booking detail page**: same three-source resolution. Task summary for project rows shows `<form_title> — Day N of M` so the admin list can tell which day of which project each row represents (a 5-day project produces 5 rows, all with the same client + address but distinct start/end windows and distinct Day labels).
* **Backfill**: nothing. Pre-existing project bookings stay invisible until they're naturally re-confirmed via webhook (cancel / reschedule), which now writes the mirror through `dispatch_project`. Same call we made in 1.5.0 for pre-existing direct bookings — acceptable for completed past visits already recorded under the Additional Forms sub-screen.
* **Plugin DI**: `Project_Schedule_Service::__construct` gains an optional `$bookings` arg (null-default so legacy construction paths in admin modules / tests keep working). `class-plugin.php` now passes `$this->bookings`.

= 2.1.22.4 =
* **P0 production hotfix — Additional Forms: address input lost the picked suggestion on blur (the real root cause).** 2.1.22.3 made the field forgiving of partial Place Details responses, but the owner reported clicking a suggestion still didn't insert the address and Continue still surfaced "Choose a valid address from the suggestions". This release fixes the actual sequence-of-events bug.
* Root cause: Google's Autocomplete library commits a selection by setting `input.value` and then synchronously calling `input.blur()` BEFORE it fires `place_changed`. Our `onBlur` in `booking-forms.js` had a catch-all "re-render on any blur on the details step" branch (a leftover from when blur drove error-message refreshes for the name/phone/email fields). That re-render ran first, blew away the entire `[data-handik-booking-form-shell]` subtree, and `afterRender` immediately re-mounted a fresh `google.maps.places.Autocomplete` instance against the freshly-built input — overwriting `self.addressAutocomplete`. By the time `place_changed` finally fired on the original (now-stale) listener registration, `self.addressAutocomplete.getPlace()` was reading from the NEW Autocomplete instance, which had never had a selection. Result: empty place data, our parser kept the previously-typed query as `address_full`, `is_valid` stayed `false`, the customer saw their typed query (not the chosen suggestion) and Continue rejected the address. The main SPA (`booking-app.js`) has never had this bug because it only attaches blur listeners to specific contact fields (`contact.full_name`, `contact.phone`, `contact.email`) — booking-app.js:3753-3773 — and deliberately leaves the address input alone.
* Fix: short-circuit `onBlur` in `booking-forms.js` for `address.address_full` and `address.address_unit` after flipping `touched`. The re-render path that other fields rely on still runs for them; the address field is now blur-no-op, which means Google's `place_changed` fires against the still-current Autocomplete instance and the selected address lands in state cleanly. The 2.1.22.3 parseAddressComponents `prev` fallback and explicit `input.value =` write are kept as defense-in-depth for keys with degraded Place Details.
* Not migrating to `google.maps.places.PlaceAutocompleteElement` — both SPAs continue to use the legacy `google.maps.places.Autocomplete` constructor. The new web component is a much larger UX change (custom-element styling story, different event surface, requires `loading=async`, no longer pixel-compatible with the existing input markup) and would have to ship symmetrically across both SPAs. Booked as a Sprint 11 item.
* No server change, no settings change, no DB migration. Cache invalidates via the bumped `HANDIK_BOOKING_APP_VERSION` query param.

= 2.1.22.3 =
* **P0 production hotfix — Additional Forms: clicking a Places suggestion wiped the address instead of inserting it.** Follow-up to 2.1.22.2. Now that `mountAddressAutocomplete()` actually fires on the `details` step the dropdown appears, but on click the input was being cleared.
* Root cause: `parseAddressComponents` in `booking-forms.js` was hard-resetting every field to empty string when Google returned a partial place. Google can fire `place_changed` with a "lite" place object (just `name` set to the typed query, no `address_components` / `formatted_address` / `geometry`) on API keys where the **Place Autocomplete** suggestion endpoint is unrestricted but the follow-up **Place Details** call is rate-limited, restricted to a different referrer, or gated behind a separate billing SKU. With nothing to parse, the old code overwrote `state.address.address_full` (and every other field) with `''`, the re-render rebuilt the input with `value=""`, and from the customer's perspective the address vanished the moment they clicked a suggestion.
* Fix mirrors what the main SPA (`booking-app.js`) has always done: pass the previous `state.address` into `parseAddressComponents` as a fallback (so partial places preserve whatever fields they don't provide), and write `input.value = state.address.address_full || input.value` to the still-attached input *before* the re-render so even a totally-empty place keeps whatever Google wrote to the input when the suggestion was clicked. Both changes are direct ports from `booking-app.js:3508-3522` and `booking-app.js:3562`.
* No server change, no settings change, no DB migration. Bumping the plugin version invalidates the cached `booking-forms.js` (it's enqueued with `HANDIK_BOOKING_APP_VERSION` as the cache-buster), so customers get the fix on their next page load.
* Recommended sanity check after deploying: in the Google Cloud Console, verify the Maps API key has both **"Places API"** (for the dropdown suggestions) AND **"Places API (New)"** / **"Geocoding API"** unrestricted for the production domain — the fallback above keeps the UI from breaking when Place Details is degraded, but you'll still want fully-validated addresses (`is_valid: true`) hitting the CRM for ZIP / city / state, otherwise the Continue button blocks at the address-validation gate.

= 2.1.22.2 =
* **P0 production hotfix — customers couldn't book through Additional Forms.** Owner reported "Google Maps API не предлагает адреса". Customer types address into the input, no dropdown appears, can't proceed (Continue button shows `errorAddressInvalid` toast because the address never reached Places-verified state).
* Root cause: Sprint 5 combined the separate `contact` + `address` steps in `booking-forms.js` into a single `details` step, but the `afterRender` hook that mounts Google Maps Places Autocomplete was still gated on the **old** `'address'` step name. So `mountAddressAutocomplete()` literally never fired on Additional Forms — the Maps JavaScript API script was never even requested (which is why there was no console error: the browser had no fetch to fail). The plain text input stayed dumb; customer-entered addresses never reached `is_valid: true`; the address-required validation rejected them.
* Fix: one-line `'address' === this.state.step` → `'details' === this.state.step` in `booking-forms.js::afterRender`. Main SPA (`booking-app.js`) was unaffected — it uses a separate `address_details` step name and its mount hook was correctly wired.
* Bumping the plugin version invalidates the browser's cached `booking-forms.js` (the asset is enqueued with `HANDIK_BOOKING_APP_VERSION` as a cache-buster query param), so customers pick up the fix on their next page load.
* No DB change. No settings change. No security implications.

= 2.1.22.1 =
* **P0 hotfix — `{{booking_when_long}}` was rendering empty + `.ics` not attaching** in customer-confirmation emails. Owner-reported via real-booking test: subject came through as "Booking confirmed —" (placeholder empty), body said "Your visit is confirmed for ." (also empty), no calendar attachment despite the body promising one.
* Root cause: Cal embed v2's `bookingSuccessful` event passes data with the start time at `payload.date` (top-level) OR `payload.booking.startTime` (nested), plus a separate `duration` field. The plugin's `flatten_when_single()` was only checking top-level `startTime` / `start` (the Cal **webhook** payload shape), so it returned an empty string. That cascaded: empty `start_iso` → empty `{{booking_when_long}}` → `Ics_Builder::build_single` bails (DTSTART required) → no `.ics` attached. Same bug in `extract_cal_url` (nested `rescheduleUrl`) and `extract_cal_uid` (nested `uid`).
* Fix in `Notifications_Service`: probe order extended to cover Cal v2 top-level (`date`), nested (`booking.startTime`), AND derive `end_iso` from `start + duration` when only the start ships. `extract_cal_url` + `extract_cal_uid` now also probe inside the nested `booking` object. Verified end-to-end with a 6-case smoke test covering Cal v2 minimal, Cal v2 nested, Cal webhook (regression check), empty payload, and missing-end-with-duration.
* **P0 also — empty "What we'll be doing:" line.** Direct flow: when the booking preset had no human-readable `label` field, the customer email rendered the section header with no list under it. Now falls back to `payload.eventType.title` / `payload.type` (Cal carries the event-type label there, e.g. "Standard Visit") — same fallback wired for the main SPA flow when `selected_tasks` is empty.
* **Cal-style branded default template** for `customer_confirmation_body_html`. Replaces the bare-paragraph default with a centered card layout: green checkmark badge at top, "Booking confirmed" heading, structured "What / When / Where" table with each row hidden when its data is empty (no more dangling "Where: " lines), Cal-style "Reschedule or cancel" button using `{{cal_url}}` (renders ONLY when Cal shipped a URL). Three new pre-rendered placeholders make it work: `{{checkmark_block_html}}`, `{{booking_summary_block_html}}`, `{{cal_links_block_html}}`. All three are allow-listed past the HTML-escape pass since they're built with internal escape passes.
* **"Reset to default" buttons** under the customer-confirmation section. Existing installs (anyone who enabled customer confirmations on 2.1.21.0–2.1.22.0) have an older saved template that won't auto-update — defaults only land on fresh activation. New buttons reset the saved value of subject / HTML body / plain-text body to the bundled current default in one click. Resettable-key allow-list defends against forged forms resetting unrelated settings.
* New `Settings::reset_to_default( $key )` API. Callable from any code that wants to revert one setting; bypasses sanitize_settings() because the default is already trusted.
* No DB change, no breaking change, no behaviour change for installs that haven't enabled customer confirmations.

= 2.1.22.0 =
* **Sprint 14c — cancellation + reschedule emails.** When Cal.com webhooks a cancellation or time change, the plugin now sends customer + owner emails about it (separate toggles, both default OFF). Customer side gets a branded HTML email with proper RFC 5546 .ics attachment so the booking is removed from / updated in their calendar on import. Owner side gets a plain-text "Cancelled / Rescheduled — Jane on Tue" notification.
* **DB migration 1.5.2** (idempotent, safe to re-run). Adds nullable `last_status_emailed VARCHAR(16)` column to `handik_bookings` and `handik_direct_booking_requests`. The new column tracks the last booking-status we emailed about (booked / cancelled / rescheduled) so webhook retries with the same status are no-ops AND legitimate state transitions (booked → cancelled, or cancelled → rebooked) each fire one email. Project work-days schedules are NOT included — per-day Cal bookings make per-schedule cancel/reschedule semantics ambiguous; project flow is deferred to v2.
* **Two new actions for third-party listeners.** `do_action( 'handik_booking_cancelled', $context )` and `do_action( 'handik_booking_rescheduled', $context )` fire alongside the existing `handik_booking_confirmed` from Sprint 14a. Same context shape; reschedule events also include `$context['old_when']` (start_iso / end_iso of the previous time) and cancellations include `$context['cancellation_reason']`. Owners can hook these for Slack / SMS / etc. without forking — same extensibility pattern as the existing booking-confirmed action.
* **`Ics_Builder` extended** with three new per-event params:
  - `$method` (parameter on `build_single` / `build_multi`): RFC 5546 `METHOD:CANCEL` (cancellation) vs. `METHOD:REQUEST` (booked + reschedule). Allow-listed against `REQUEST` / `CANCEL` / `PUBLISH` / `REPLY` so a forged value can't header-inject.
  - `event['status']`: per-event RFC 5545 `STATUS:CANCELLED` vs. `STATUS:CONFIRMED`. Allow-listed against `CONFIRMED` / `CANCELLED` / `TENTATIVE`.
  - `event['sequence']`: bumped from `0` to `1` for reschedule + cancel events. RFC 5545 §3.8.7.4 — calendar apps update the existing event vs creating a duplicate when the SEQUENCE increments. The original `UID` is preserved across all three event types so calendars match.
* **12 new settings on App Setup → Customer notifications:**
  - Customer cancellation: `customer_cancellation_enabled` (toggle, default 0), `customer_cancellation_subject`, `customer_cancellation_body_html`, `customer_cancellation_body_text`. New section on the tab.
  - Customer reschedule: `customer_reschedule_enabled`, `customer_reschedule_subject`, `customer_reschedule_body_html`, `customer_reschedule_body_text`. New section.
  - Owner side reuses the existing `owner_notification_enabled` toggle from Sprint 14b but adds `owner_cancellation_subject` + `owner_cancellation_body` and `owner_reschedule_subject` + `owner_reschedule_body`. Existing "Owner booking notification" section now has three sub-blocks (New booking / Cancellation / Reschedule) each with its own Send-Test button.
* **New placeholders** for the cancel/reschedule templates: `{{cancellation_reason}}` (Cal-payload-extracted; empty string when absent), `{{old_booking_when}}` and `{{old_booking_when_long}}` (the previous time before reschedule, formatted in the site timezone).
* **Four new Send-Test buttons** (`send_test_customer_cancellation`, `send_test_customer_reschedule`, `send_test_owner_cancellation`, `send_test_owner_reschedule`) reuse the same `notification_test_recipient` field from 2.1.21.3 — set the field once, all 6 buttons honor it. Sample-data context for the cancel/reschedule previews populates `old_when` (a fictional 2-days-ago time) and `cancellation_reason` so the rendered placeholders look realistic instead of empty.
* **Master toggle defaults OFF on upgrade.** Existing installs see no behavioural change. Cal.com keeps sending its own cancellation / reschedule emails (from the workflow side) until the owner manually disables them — same pattern as Sprint 14a's booking-confirmation Cal-disable instructions.
* **Out-of-scope (still v2):** Project work-days cancellation / reschedule (semantically tricky), customer-initiated reschedule UI, N-hour reminders, admin "Resend cancellation/reschedule" buttons on the booking-detail page.

= 2.1.21.4 =
* **Branded customer-confirmation default template + Brand logo URL setting.** New `brand_logo_url` setting on the Notifications tab → drops a public HTTPS URL (e.g. your logo on the site's media library) and the default HTML template renders it centered above the booking details. Empty → no logo block, just the text-first email. Two new placeholders: `{{brand_logo_html}}` (full `<img>` block, or empty string when no URL is set; safe to drop into any HTML template) and `{{brand_logo_url}}` (raw URL, esc_url'd at render time so `javascript:` schemes can't slip through).
* **The default `customer_confirmation_body_html` is now a polished table-based layout** with system fonts, a 560px max-width, an off-white card on a light background, and a footer linking back to the site. Existing installs keep whatever they had saved — only fresh activations land the new default. To adopt it on an existing install, clear the HTML body field and save (the default repopulates).
* No DB change, no behaviour change for production sends. URL setting sanitizes via `esc_url_raw` on save, `esc_url` at render time, and the `<img>` block is allow-listed past `placeholders_for_html`'s escape pass so the markup survives.

= 2.1.21.3 =
* **Test recipient field on the Notifications tab.** Owner request: route "Send test email" previews to a shared inbox (e.g. `hello@handik.pro`) without changing the WordPress profile email of whoever's logged in. New top section on App Setup → Customer notifications with a single field that both Send Test buttons honor. Resolution order: unsaved form value → saved setting → fallback to current admin's WP user email. Caption next to each Send Test button now shows the actual address that's about to receive the preview.
* No DB change, no behaviour change for production sends. The `email_from_address`, `customer_confirmation_reply_to`, and `owner_notification_address` settings continue to drive real (non-test) sends as before.

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
