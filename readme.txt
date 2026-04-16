=== Handik Booking App ===
Contributors: handik
Requires at least: 6.4
Requires PHP: 7.4
Stable tag: 2.0.6
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
