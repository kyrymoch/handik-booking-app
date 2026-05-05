=== Handik Booking App ===
Contributors: handik
Requires at least: 6.4
Requires PHP: 7.4
Stable tag: 2.1.8.2
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
= 2.1.8.2 =
* **Operational dashboard (A1)**: replaces the static metadata page. Five blocks — Today / Tomorrow / This week stat strip, Next 5 visits compact list, Action-needed chips (drafts / ready-not-booked / unsafe / errors), This-month-at-a-glance (count, revenue estimate, avg duration), and the changelog collapsed. All times in Eastern. Aggregate counts cached for 60 seconds via transient.
* **Bookings list (B1)**: mobile cards on <1024px and a 5-column desktop table; filters Time/Status/Search persist in the URL; upcoming first, then a dated divider, then past bookings.
* **Booking detail (B2)**: sticky top action bar with Call / Apple Maps / Cal.com link; new "At a glance" 4-cell grid; photos surfaced with lightbox; assistant summary + estimate as a printable-style block; Selected tasks as a labeled list; embedded map; Technical details and Chat-activity collapsed.
* **Real chat transcript (B3)**: new `*_handik_messages` table (migration 1.3.0), `Messages_Service`, `/messages/record` REST endpoint, and ChatKit-bridge auto-mirroring of user/assistant messages with de-dupe. Booking detail now shows a real conversation in chat bubbles instead of grepped log entries.
* **Booking actions (B4)**: Add private note (modal + textarea), Mark as cancelled, Mark as completed. New `bookings.admin_notes` and `bookings.admin_status_override` columns persist these — Cal.com webhook updates respect manual overrides.
* **Unified People view (C1)**: one row per contact with addresses / requests / bookings counts and last-seen-relative time; filters All / With bookings / Drafts only / No address; debounced search by name/phone/email; new `contacts.is_spam` column with one-click hide and a "Show N hidden" toggle; legacy three-table-dump removed (lives in System info → Raw tables).
* **Person detail (C1+C3)**: header with phone/email tap-actions, inline edit form (name, phone, email, notes, returning, spam), per-address actions (set primary, edit, soft-delete via `addresses.deleted_at`), and unified Requests/Bookings list. Phone changes log a warning entry.
* **Request detail (C2)**: new page `?page=handik-booking-app-crm&request_id=N` with the same blocks as a booking minus Cal IDs; banner explaining where the customer dropped off; "Send the customer their booking link" mailto for ready-not-booked.
* **Add person (C3)**: form to manually add a contact (and optional initial address) from the admin.
* **App Setup re-org (D1-D4)**: 6 tabs — Booking flow / Appearance / Service catalog / Service area / Cal.com / Customer notifications. Each setting key now appears exactly once. Cal.com event URLs and the new fallback URL moved out of Integrations into App Setup. Removed the General tab; Behavior moved into Appearance.
* **Service catalog editor (D2)**: drag-to-reorder via SortableJS, inline editing with auto-save (`POST /admin/catalog`), per-task Duplicate, soft-confirm Delete, "Saving / Saved / Failed" status indicator.
* **Customer notifications (D4)**: editable Cal.com confirmation note and magic-link email subject/body with `{{placeholders}}` (`{{request_id}}`, `{{customer_name}}`, `{{address}}`, `{{task_summary}}`, `{{magic_link}}`, `{{site_name}}`).
* **System info page (D5)**: plugin version, DB version, PHP/MySQL versions, total counts, "Run pending migrations" + "Clear plugin transients" buttons, and the legacy raw-tables view as a "Raw tables (debug)" tab. Log retention configurable here.
* **Logs (E1+E2)**: full level set — `debug / info / notice / warning / error / critical` — with new `Logger::warning()`, `Logger::notice()`, `Logger::critical()` methods and per-level retention (default 2000 / 500). Card-list rendering with collapsible JSON details, filters by level / time / request_id / thread_id / search (URL-persistent), CSV export. `request_id` and `thread_id` in log entries are now clickable links to the right detail page.
* **Mobile-first admin CSS (F1)**: responsive grids, ≥44px tap targets, ≥16px form inputs to prevent iOS zoom, sticky bars with `-webkit-sticky`, horizontally-scrolling tabs, tables that degrade to card lists at <1024px.
* **Bottom nav on mobile (F1)**: fixed bar with Dashboard / Bookings / People / Setup / Logs visible only at <768px; safe-area-aware padding.
* **Toasts, modals, lightbox (F2)**: replace native `confirm()` and silent saves; copy-on-tap for phone/email; debounced search forms.
* **REST surface**: new admin endpoints (`/admin/booking/*/notes`, `/admin/booking/*/status`, `/admin/contact[/*]`, `/admin/address/*[/primary]`, `/admin/catalog`, `/admin/migrations/run`, `/admin/transients/clear`) — all gated behind `manage_options` and the `wp_rest` nonce.
* **Reorganized PHP**: admin code split from a 1k-line monolith into `includes/admin/*` (helpers, dashboard, bookings, people, settings, logs, system, integrations) with a thin coordinator in `includes/class-admin.php`.

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

