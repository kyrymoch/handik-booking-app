=== Handik Booking App ===
Contributors: handik
Requires at least: 6.4
Requires PHP: 7.4
Stable tag: 2.0.18
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
