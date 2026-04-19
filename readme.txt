=== Handik Booking App ===
Contributors: handik
Requires at least: 6.4
Requires PHP: 7.4
Stable tag: 2.0.39
License: Proprietary

Single-page booking application for Handik with local CRM, hosted ChatKit, returning-client auth, Cal.com booking orchestration, and GitHub-powered plugin updates.

== Description ==

Handik Booking App turns the plugin itself into the booking experience.

Features:

* single-page multi-step booking wizard
* shortcode and Elementor widget embedding
* hosted ChatKit assistant step
* local CRM tables for contacts, addresses, requests, bookings, and login tokens
* returning-client email/phone verification with one-time code flow
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
