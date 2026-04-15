# Handik Booking App

## Architecture Summary

Handik Booking App is a standalone WordPress plugin application. The plugin renders the booking experience itself as a single-page multi-step wizard and can be embedded via shortcode or Elementor widget.

Core layers:

1. Frontend booking app
   - single-page step engine
   - hosted ChatKit assistant step
   - returning-client verification
   - final Cal.com booking step

2. Backend services
   - local CRM tables
   - routing / booking-type selection
   - hosted ChatKit session endpoint
   - one-time code / magic-link auth
   - Cal.com booking URL builder
   - Cal webhook sync

3. Admin app
   - top-level `Handik Booking` menu
   - dashboard, requests, contacts, addresses, bookings
   - app settings, integrations, logs, changelog

## Plugin Tree

```text
handik-booking-app/
  handik-booking-app.php
  README.md
  uninstall.php
  assets/
    booking-app.css
    booking-app.js
    booking-app-admin.css
    booking-app-admin.js
    handik-chatkit-bridge.js
  includes/
    class-admin.php
    class-assets.php
    class-frontend-app.php
    class-loader.php
    class-logger.php
    class-plugin.php
    class-rest-api.php
    class-settings.php
    class-shortcode.php
    class-widget-registry.php
    app/
      class-app-controller.php
      class-app-schema.php
      class-app-state.php
      class-upload-service.php
    db/
      class-db.php
      class-migrations.php
      migrations/
        class-migration-100.php
        class-migration-110.php
    services/
      class-addresses-service.php
      class-appearance-service.php
      class-auth-service.php
      class-bookings-service.php
      class-cal-service.php
      class-changelog-service.php
      class-chatkit-service.php
      class-contacts-service.php
      class-job-requests-service.php
      class-routing-service.php
      class-webhook-service.php
  views/
    frontend-app.php
  widgets/
    class-elementor-booking-app-widget.php
```

## Frontend App Flow

The app runs on one page and moves through these screens:

1. Welcome / Entry
2. Client Type
3. Returning Client Verification
4. Task Selection
5. Address + Photos
6. Assistant Step
7. Contact Details
8. Booking
9. Success
10. Unsafe / Stop

## Booking Types

- `standard_visit`
- `extended_visit`
- `large_visit`
- `project_consultation`

## Duration Buckets

- `1_2_hours`
- `3_5_hours`
- `6_7_hours`
- `project_consult`

## Local CRM Tables

- `wp_handik_contacts`
- `wp_handik_addresses`
- `wp_handik_job_requests`
- `wp_handik_bookings`
- `wp_handik_login_tokens`

The migration framework stores the current DB schema version in:

- `handik_booking_app_db_version`

## Migration Strategy From The Old Plugin

This plugin keeps the useful foundation from the previous Handik Booking Hub:

- table names and CRM concepts
- returning-client auth
- hosted ChatKit session exchange
- routing logic
- Cal.com helpers
- webhook sync structure

What changed:

- Elementor forms are no longer the main flow engine
- the plugin now owns the full booking wizard
- shortcode and Elementor widget render the plugin app UI directly
- admin moved to a top-level menu
- migrations are versioned for future schema changes

## Embedding

### Shortcode

```text
[handik_booking_app]
```

Optional shortcode attributes:

- `title`
- `accent`
- `max_width`
- `display`

### Elementor Widget

Widget name:

- `Handik Booking App`

Controls:

- title
- accent override
- max width

## Hosted ChatKit

The plugin uses hosted ChatKit, not advanced self-hosted ChatKit.

Backend endpoint:

- `POST /wp-json/handik-booking-app/v1/chatkit-session`

OpenAI session request:

- `workflow.id`
- `user`

The bundled bridge:

- mounts the hosted `openai-chatkit` web component
- fetches the session from the WordPress backend
- associates the current thread with the draft request
- posts normalized structured results to `/assistant-result`
- emits detailed diagnostics for session fetch, mount, ready, thread changes, message/effect events, and runtime errors

Important compatibility note:

- the current hosted ChatKit session endpoint accepts `workflow.id` and `user`
- attempted `state_variables` were rejected by the upstream API with `Unknown parameter: 'state_variables'`
- the plugin still preserves draft context locally and returns it to the frontend bridge, but does not send `state_variables` upstream until OpenAI exposes a compatible hosted-session context field

Completion signals expected from the workflow:

- effect names: `handik.assistant_complete`, `handik.complete`, `assistant.complete`
- deeplink names: `handik-submit-result`, `handik-complete`

## Admin Pages

- Dashboard
- Requests
- Contacts
- Addresses
- Bookings
- App Settings
- Integrations
- Logs
- Changelog

## Settings

App Settings include:

- OpenAI API key
- OpenAI workflow ID
- OpenAI API base
- OpenAI project ID
- OpenAI organization ID
- custom ChatKit bridge URL
- Cal.com URLs for all 4 booking types
- Cal.com webhook secret
- email sender settings
- debug mode
- appearance settings

## Diagnostics

For assistant troubleshooting, the plugin now logs both backend and frontend ChatKit stages.

Examples:

- session requested
- session fetched
- client secret normalized
- custom element defined
- mounted into container
- ready event fired
- thread associated
- effect/deeplink received
- runtime error

The admin logs screen now includes serialized `context` so you can see request ids, thread ids, OpenAI request ids, and mount-stage details.

## wp-config Constant Overrides

- `HANDIK_BOOKING_APP_OPENAI_API_KEY`
- `HANDIK_BOOKING_APP_OPENAI_WORKFLOW_ID`
- `HANDIK_BOOKING_APP_OPENAI_API_BASE`
- `HANDIK_BOOKING_APP_OPENAI_PROJECT_ID`
- `HANDIK_BOOKING_APP_OPENAI_ORGANIZATION_ID`
- `HANDIK_BOOKING_APP_CHATKIT_SCRIPT_URL`
- `HANDIK_BOOKING_APP_CAL_STANDARD_EVENT_URL`
- `HANDIK_BOOKING_APP_CAL_EXTENDED_EVENT_URL`
- `HANDIK_BOOKING_APP_CAL_LARGE_EVENT_URL`
- `HANDIK_BOOKING_APP_CAL_PROJECT_EVENT_URL`
- `HANDIK_BOOKING_APP_CAL_WEBHOOK_SECRET`
- `HANDIK_BOOKING_APP_EMAIL_FROM_NAME`
- `HANDIK_BOOKING_APP_EMAIL_FROM_ADDRESS`
- `HANDIK_BOOKING_APP_DEBUG_MODE`

## Installation

1. Copy `handik-booking-app` into `wp-content/plugins/`.
2. Activate the plugin.
3. Open `Handik Booking > App Settings`.
4. Configure OpenAI, ChatKit, Cal.com, email sender, and appearance settings.
5. Add the shortcode or Elementor widget to the desired page.
6. Register the Cal webhook URL:
   - `https://your-site.com/wp-json/handik-booking-app/v1/cal-webhook`

## Testing Checklist

1. Plugin activation creates or upgrades local CRM tables.
2. Shortcode renders the booking app.
3. Elementor widget renders the same booking app.
4. New client can move through all steps without page reloads.
5. Returning client can request and verify one-time code.
6. Saved addresses prefill after returning-client verification.
7. Draft request is created after address + photos step.
8. Hosted ChatKit session endpoint returns `client_secret`.
9. Assistant result updates routing fields and unsafe state when applicable.
10. Contact details save before Cal.com booking step.
11. Correct Cal.com URL is built for each booking type.
12. Booking completion step moves the app into success state.
13. Cal webhook sync updates local booking status.
14. Admin pages show requests, contacts, addresses, bookings, logs, and changelog.
