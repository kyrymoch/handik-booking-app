<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Handik_Booking_App_Changelog_Service {
	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_entries() {
		return array(
			array(
				'version'      => '2.0.39',
				'date'         => '2026-04-19',
				'title'        => 'Warm Uploaded Photo Analysis Before Mounting The Virtual Assistant',
				'notes'        => array(
					'The assistant step now prepares uploaded-photo analysis before the first hosted ChatKit mount for new photo-backed requests, which removes the old race where the assistant could greet the customer before photo context was ready.',
					'ChatKit bridge callbacks now receive the full session payload on onSessionReady, so the booking app can use draft-context photo analysis that already exists on the server without guessing.',
					'Added an assistant preparation loading state and photo-context handoff after session readiness so uploaded-photo context reaches the live conversation in a more predictable order.',
				),
			),
			array(
				'version'      => '2.0.38',
				'date'         => '2026-04-19',
				'title'        => 'Inject Uploaded Photo Summary Into The Live Assistant Thread',
				'notes'        => array(
					'When backend photo-analysis finishes, the booking app now sends a compact HANDIK_CONTEXT message into the live hosted ChatKit thread so the assistant can use the uploaded-photo summary during the current conversation.',
					'The context injection includes photo summary, estimate notes, visible tasks, and visible cautions, and it is de-duplicated by photo signature so the same photo batch is not repeatedly announced.',
					'Added explicit logs for assistant photo-context message start, completion, and failure so it is clear whether the uploaded-photo summary actually reached the live chat thread.',
				),
			),
			array(
				'version'      => '2.0.37',
				'date'         => '2026-04-19',
				'title'        => 'Keep Assistant Chat Mounted During AI Review Photo Uploads',
				'notes'        => array(
					'Stopped forcing a full assistant-step rerender while Add photos for AI review is uploading files, because that rerender was remounting the hosted ChatKit widget and breaking the live thread before the next user message.',
					'The assistant photo button now uploads and warms photo-analysis in the background without rebuilding the chat host, so the same chat instance stays mounted and ready to accept the next message.',
					'Non-assistant photo uploads keep their normal loading overlay behavior, but the Virtual assistant step now preserves the active chat surface during AI-review photo saves.',
				),
			),
			array(
				'version'      => '2.0.36',
				'date'         => '2026-04-19',
				'title'        => 'Stabilize AI Review Photos By Using Saved Request Images',
				'notes'        => array(
					'Stopped sending Add photos for AI review files into the hosted ChatKit composer because that native file handoff was causing the assistant conversation to stop responding after the next user message.',
					'The assistant-step photo button now saves the images to WordPress CRM and immediately warms backend photo-analysis from those saved request files instead of trying to attach them inside the chat composer.',
					'This keeps the chat responsive while preserving the real business goal: the AI can still review the uploaded photos and the CRM still stores them on the request.',
				),
			),
			array(
				'version'      => '2.0.35',
				'date'         => '2026-04-18',
				'title'        => 'Stop Infinite Loading On Assistant Photo Uploads',
				'notes'        => array(
					'Added timeout protection around the assistant addFiles handoff so the Virtual assistant photo loader can no longer spin forever when hosted ChatKit does not resolve the file-preparation call.',
					'Moved backend photo-analysis warmup out of the blocking upload chain so saved request photos can continue processing in the background without holding the screen loader open.',
					'Added focused assistant photo-flow logs for addFiles start, completion, timeout/failure, and photo-analysis warmup start/completion to isolate exactly where the assistant photo branch stops.',
				),
			),
			array(
				'version'      => '2.0.34',
				'date'         => '2026-04-18',
				'title'        => 'Enable Native Chat Attachment Path For AI Review Photos',
				'notes'        => array(
					'Enabled the hosted ChatKit composer attachment configuration so the assistant-step Add photos for AI review control now feeds files into a fully attachment-capable chat composer.',
					'The same assistant-step photo picker still dual-uploads the selected images into WordPress CRM, while the chat side now uses the supported native file upload path instead of a half-configured composer state.',
					'Mirrored the chat file-upload limits from the session response into the composer configuration and added the file-upload config to ChatKit session logs for easier validation.',
				),
			),
			array(
				'version'      => '2.0.33',
				'date'         => '2026-04-18',
				'title'        => 'Rollback To Stable Assistant Photo Flow',
				'notes'        => array(
					'Rolled back the experimental assistant photo-upload diagnostics that interfered with hosted ChatKit connection stability on the Virtual assistant step.',
					'Restored the previously working assistant photo flow from 2.0.31 so OpenAI chat sessions can mount and run normally again.',
					'Kept the Add photos for AI review control in place while returning the assistant bridge to the last stable release behavior.',
				),
			),
			array(
				'version'      => '2.0.31',
				'date'         => '2026-04-18',
				'title'        => 'Assistant Step Dual Upload For AI Review Photos',
				'notes'        => array(
					'Added a dedicated Add photos for AI review control on the Virtual assistant step so clients can upload images right where they ask the AI for help.',
					'Those assistant-step images now dual-upload: they are saved into the WordPress request record for CRM visibility and also queued into the hosted ChatKit composer for the next user message.',
					'If the hosted ChatKit composer accepts the files, the same photos can travel with the user message while backend photo analysis still runs from the WordPress copies as a reliable fallback.',
				),
			),
			array(
				'version'      => '2.0.30',
				'date'         => '2026-04-18',
				'title'        => 'Photo Vision Analysis From WordPress Uploads',
				'notes'        => array(
					'Added a backend photo-analysis pass that reviews the uploaded WordPress request photos with OpenAI vision and caches the result on the request record.',
					'The booking flow now warms that photo analysis in the background on the Virtual assistant step and merges the visual observations into estimate notes before routing and booking decisions are saved.',
					'Bookings admin now shows the cached uploaded-photo analysis alongside the latest assistant output so the final CRM record makes it clear what the system inferred from the images.',
				),
			),
			array(
				'version'      => '2.0.29',
				'date'         => '2026-04-18',
				'title'        => 'Photo Handoff Into The Virtual Assistant Composer',
				'notes'        => array(
					'Photos selected on the Photos step now stay available in browser memory for the virtual assistant while still uploading to WordPress for CRM and admin storage.',
					'When the hosted ChatKit assistant becomes ready, the bridge preloads those pending images into the composer so the client can send their first real message together with the same photos.',
					'Once the client submits that first message, the pending photo queue is cleared so the same files are not silently reattached again on later assistant visits.',
				),
			),
			array(
				'version'      => '2.0.28',
				'date'         => '2026-04-18',
				'title'        => 'Flow Reorder, Better Booking Admin, And More Stable Assistant Mounting',
				'notes'        => array(
					'Reordered the booking flow to move through address, tasks, photos, contact details, virtual assistant, and booking, while moving the action dock and compact progress bar to the bottom on desktop and mobile.',
					'Expanded the Bookings admin view with assistant output JSON, direct OpenAI thread-log links, and list columns centered on the fields that matter operationally: client info, task summary, rate hint, address, assistant summary, and schedule.',
					'Removed the app-owned success step and stopped double-mounting hosted ChatKit when entering the assistant screen, which should make the assistant step noticeably more stable.',
				),
			),
			array(
				'version'      => '2.0.27',
				'date'         => '2026-04-18',
				'title'        => 'Quieter Info Mode Toggle',
				'notes'        => array(
					'Made the Info mode button smaller, calmer, and less decorative so it feels more like a subtle utility control than a prominent action.',
					'Extended the toggle to hide warning notices as well, leaving success and true error messages visible while helper guidance is off.',
					'Added short forced status messages when the toggle changes so clients see a clear “Hints are enabled” or “Hints are disabled” confirmation.',
				),
			),
			array(
				'version'      => '2.0.26',
				'date'         => '2026-04-18',
				'title'        => 'Info Mode Toggle And Simpler Notification Copy',
				'notes'        => array(
					'Added an Info mode toggle button to the booking UI with a short onboarding tooltip and local preference caching so helper tips stay on or off based on the client choice.',
					'Simplified client-type and task notifications by removing extra titles, removing the task-details pill, and keeping task notices focused on the description plus hourly rate.',
					'Updated warning copy to read more naturally inline and softened the notification animation so appearance and fade-out feel smoother.',
				),
			),
			array(
				'version'      => '2.0.25',
				'date'         => '2026-04-18',
				'title'        => 'Notification Portal Above Site Header',
				'notes'        => array(
					'Moved interactive notifications out of the booking app container and into document.body so they are no longer trapped inside the page builder stacking context.',
					'This fixes the case where the site header or another fixed menu could still appear above notifications despite the toast layer using a very high z-index.',
					'The notification layer now behaves like a true global overlay while keeping the same bottom-right desktop placement and top placement on mobile.',
				),
			),
			array(
				'version'      => '2.0.24',
				'date'         => '2026-04-18',
				'title'        => 'Notification Placement And Timer Polish',
				'notes'        => array(
					'Shrank desktop interactive notifications and moved them to the bottom-right corner to match the intended compact reference layout more closely.',
					'Kept mobile notifications at the top with a much higher stacking layer so they always sit above the booking UI.',
					'Fixed notification pause and resume behavior so the progress bar and timeout continue from the remaining time instead of visually restarting from the beginning.',
				),
			),
			array(
				'version'      => '2.0.23',
				'date'         => '2026-04-18',
				'title'        => 'Interactive Notification System Refresh',
				'notes'        => array(
					'Replaced the old mix of inline hints, footer bubbles, and assistant helper blocks with one unified interactive notification system across the booking app.',
					'Added success, error, warning, info, and task-description notifications with richer dark styling, timed progress bars, close controls, and pause-on-hover or touch-hold behavior.',
					'Task selections, client-type choices, and blocked Continue actions now use the new notification layer instead of the previous scattered tooltip and hint UI.',
				),
			),
			array(
				'version'      => '2.0.22',
				'date'         => '2026-04-17',
				'title'        => 'Admin Booking Details And Cleaner CRM Navigation',
				'notes'        => array(
					'Moved Bookings into its own admin menu section and regrouped requests with contacts and addresses under a clearer Clients & Requests CRM area.',
					'Added a detailed booking view with booking status, Cal data, client info, request details, address map, attached photos, and saved chat activity for the request.',
					'Expanded admin styling so booking details are easier to scan with cards, chips, galleries, and structured log blocks.',
				),
			),
			array(
				'version'      => '2.0.21',
				'date'         => '2026-04-17',
				'title'        => 'Cal Embed Bootstrap Fix',
				'notes'        => array(
					'Switched Cal.com loading from a plain script include to the official bootstrap-snippet pattern that creates window.Cal, the command queue, and namespace APIs reliably.',
					'This fixes the booking-step failure where the app was falling back to iframe mode because Cal embed never exposed the expected API object.',
					'Cal booking capture, logging, and success listeners now run on top of the proper bootstrap flow instead of the previous brittle script-load assumption.',
				),
			),
			array(
				'version'      => '2.0.20',
				'date'         => '2026-04-17',
				'title'        => 'Cal Embed Diagnostics And Cleaner Booking Screen',
				'notes'        => array(
					'Removed the old booking status banner from the booking step so the calendar can sit cleanly inside the app without the extra framed note.',
					'Added explicit Cal embed client logs for mount, ready, failure, and booking-success events so booking sync problems can be diagnosed from the WordPress log screen.',
					'Registered both global and namespaced Cal embed success listeners to improve compatibility with Cal.com embed event delivery.',
				),
			),
			array(
				'version'      => '2.0.19',
				'date'         => '2026-04-17',
				'title'        => 'Cal Booking Capture And Booking Screen Refresh',
				'notes'        => array(
					'Switched the booking step to use Cal embed events so a successful booking can be captured immediately in WordPress instead of waiting only for a webhook.',
					'Added a backend booking-capture endpoint that writes the confirmed Cal booking into the local CRM and finishes the flow even when webhook delivery is delayed.',
					'Redesigned the booking screen status area so it uses the current in-app UI language instead of the old plain confirmation note block.',
				),
			),
			array(
				'version'      => '2.0.18',
				'date'         => '2026-04-17',
				'title'        => 'Assistant Continue Fallback For Hosted ChatKit Thread Glitches',
				'notes'        => array(
					'Virtual assistant Continue now treats an active or restored ChatKit thread as valid client interaction, even when hosted ChatKit drops the user-message event.',
					'The Continue button can recover from the hosted ChatKit React event glitch by using the saved thread state as a fallback instead of blocking the booking flow.',
					'Assistant step state now marks thread association as meaningful interaction so clients can proceed after a real chat conversation without retyping the request.',
				),
			),
			array(
				'version'      => '2.0.17',
				'date'         => '2026-04-17',
				'title'        => 'Draft-Bound Upload Security And Returning Client History Fixes',
				'notes'        => array(
					'Changed photo uploads to require a valid draft request and draft token before files are accepted by the backend.',
					'Removed absolute server file paths from photo-upload responses so the frontend only receives safe public metadata.',
					'Fixed returning-client history loading to query recent requests for the matched contact directly instead of filtering a small global sample in memory.',
				),
			),
			array(
				'version'      => '2.0.16',
				'date'         => '2026-04-17',
				'title'        => 'Service Catalog, Photo Step, And Cleaner UI Controls',
				'notes'        => array(
					'Added an admin-managed service and category editor so the task-selection screen can be configured from the plugin without editing code.',
					'Split address and photos into separate steps, improved the photo uploader copy, and store uploads in per-contact or per-session request folders inside WordPress uploads.',
					'Removed client-card subtitles, switched client-type help to cleaner info toasts, and kept continue-blocking warnings anchored to the footer button instead of noisy inline error blocks.',
				),
			),
			array(
				'version'      => '2.0.15',
				'date'         => '2026-04-16',
				'title'        => 'Hosted ChatKit Stabilization And Bubble Hints',
				'notes'        => array(
					'Stabilized the hosted ChatKit bridge by removing unsupported composer upload configuration and unsupported composer-prefill commands that were crashing the embedded chat.',
					'Changed the assistant draft handoff to a safer auto-sent context message so the chat can resume reliably without remount failures.',
					'Removed the Back button from the first screen and upgraded client-choice plus Continue validation hints into attached bubble-style tooltips.',
				),
			),
			array(
				'version'      => '2.0.14',
				'date'         => '2026-04-16',
				'title'        => 'Admin UI Controls And Booking Reliability',
				'notes'        => array(
					'Added a much larger admin-side UI control surface for texts, labels, colors, helper copy, and scoped custom CSS overrides.',
					'Updated the booking wizard with muted Continue states, footer tooltip validation, client-type helper cards, a clearer photo uploader, and unit or apartment support.',
					'Improved assistant-step persistence plus Cal.com webhook matching with metadata, booking ID, and contact-based fallback matching for booking confirmations.',
				),
			),
			array(
				'version'      => '2.0.13',
				'date'         => '2026-04-15',
				'title'        => 'Updater Quiet Period',
				'notes'        => array(
					'Changed GitHub auto-update checks to run on a 24-hour cadence instead of the shorter default interval.',
					'Kept the manual Check for updates link in the WordPress Plugins screen for on-demand refreshes.',
					'Throttled the updater initialization log so it only appears once per day or when updater settings change.',
				),
			),
			array(
				'version'      => '2.0.12',
				'date'         => '2026-04-15',
				'title'        => 'Assistant Step Backend Fallback',
				'notes'        => array(
					'Virtual assistant Continue now re-checks the saved draft assistant result on the backend instead of relying only on the current frontend state.',
					'If ChatKit already stored a valid classification with enough_information=true, the client can move forward even when the browser missed the completion callback.',
					'Added assistant-result processing logs so it is easier to see whether routing used the newly submitted payload or the previously stored classification.',
				),
			),
			array(
				'version'      => '2.0.11',
				'date'         => '2026-04-15',
				'title'        => 'Assistant Continue Button State',
				'notes'        => array(
					'Virtual assistant Continue now stays visually muted until the workflow returns enough_information=true.',
					'The button still shows the inline assistant notice when clicked too early, instead of silently failing.',
					'Once enough information is captured, the same button turns green and behaves like the normal next-step action.',
				),
			),
			array(
				'version'      => '2.0.10',
				'date'         => '2026-04-15',
				'title'        => 'Assistant Result Capture Hardening',
				'notes'        => array(
					'Extended the ChatKit bridge to recognize classification payloads by schema shape, not only by a small set of effect names.',
					'Added fallback extraction for structured assistant output from effect, deeplink, message, and log event payloads.',
					'Broadened user-message detection heuristics so Continue no longer blocks when the client has clearly interacted with chat.',
				),
			),
			array(
				'version'      => '2.0.9',
				'date'         => '2026-04-15',
				'title'        => 'Assistant Continue Logic Fix',
				'notes'        => array(
					'Fixed the virtual assistant Continue flow so it no longer re-renders and remounts the chat before moving to the next step.',
					'Continue now follows the intended rule: block only when the client has not sent anything in chat and there is no assistant result with enough_information=true.',
					'Assistant notices are now updated inline on the current screen instead of forcing a full step re-render.',
				),
			),
			array(
				'version'      => '2.0.8',
				'date'         => '2026-04-15',
				'title'        => 'Custom Loading Polish',
				'notes'        => array(
					'Replaced simple spinners with custom handyman and virtual-assistant loading animations, plus delayed helper copy for long loads.',
					'Refined the assistant screen copy and removed the extra helper textarea plus reload control so the chat stays cleaner.',
					'Desktop back buttons now use text-only labels while mobile keeps a cleaner icon-based back action.',
				),
			),
			array(
				'version'      => '2.0.7',
				'date'         => '2026-04-15',
				'title'        => 'Assistant Flow And Mobile Controls',
				'notes'        => array(
					'Added playful loading states, desktop-centered client choice buttons, and sticky mobile navigation controls for the single-page booking flow.',
					'Removed preferred timeframe from the task step, simplified the assistant screen, and added a required quick-description fallback before continuing.',
					'Kept the same assistant thread when returning to the assistant step and updated Cal.com prefill for attendee address plus normalized US phone formatting.',
				),
			),
			array(
				'version'      => '2.0.6',
				'date'         => '2026-04-15',
				'title'        => 'Mobile Booking Flow Refresh',
				'notes'        => array(
					'Redesigned the booking flow for mobile-first use, full-width layout, centered headers, tighter task chips, and cleaner progress indicators.',
					'Added Google Maps Places autocomplete support for the single-line job address field.',
					'Embedded the Cal.com booking step in the app and now wait for webhook-confirmed booking status before showing Success.',
				),
			),
			array(
				'version'      => '2.0.5',
				'date'         => '2026-04-15',
				'title'        => 'GitHub Auto-Updates',
				'notes'        => array(
					'Added a built-in GitHub release updater so WordPress can detect plugin updates from the kyrymoch/handik-booking-app repository.',
					'Added private-repository token support, release asset matching, and updater settings in the admin app.',
					'Added release packaging files so each GitHub Release can publish a ready-to-install handik-booking-app.zip asset for WordPress auto-updates.',
				),
			),
			array(
				'version'      => '2.0.4',
				'date'         => '2026-04-15',
				'title'        => 'Assistant Step Flow Fixes',
				'notes'        => array(
					'Removed the welcome screen so the booking app now starts directly at client type selection.',
					'Added assistant-step navigation controls and a manual continue path so the flow no longer blocks when hosted ChatKit does not auto-advance.',
					'Removed short description from the address step and relaxed the assistant ready timeout when the chat is already interactive.',
				),
			),
			array(
				'version'      => '2.0.3',
				'date'         => '2026-04-15',
				'title'        => 'ChatKit Mount Diagnostics',
				'notes'        => array(
					'Adopted the more defensive hosted ChatKit mount flow from the previously working integration, including custom element readiness checks and client secret normalization.',
					'Added frontend-to-backend diagnostic logging for session fetch, mount, ready, thread, effect, message, and error stages.',
					'Expanded the admin logs screen to show serialized context for faster debugging when the assistant stalls.',
				),
			),
			array(
				'version'      => '2.0.2',
				'date'         => '2026-04-15',
				'title'        => 'ChatKit Session Payload Compatibility',
				'notes'        => array(
					'Removed unsupported state_variables from the hosted ChatKit session request after OpenAI returned a 400 unknown parameter error.',
					'Kept draft context associated locally in the plugin response and thread mapping flow while preserving hosted ChatKit.',
					'Bumped plugin assets and runtime version for cache-safe rollout.',
				),
			),
			array(
				'version'      => '2.0.1',
				'date'         => '2026-04-15',
				'title'        => 'ChatKit Session Diagnostics',
				'notes'        => array(
					'Improved hosted ChatKit session error reporting so upstream OpenAI messages are returned instead of a generic 502.',
					'Added OpenAI Project ID and Organization ID settings for project-scoped workflow access.',
					'Adjusted the returning-client wizard back navigation to stay inside the correct single-page flow.',
				),
			),
			array(
				'version'      => '2.0.0',
				'date'         => '2026-04-14',
				'title'        => 'Booking App Rebuild',
				'notes'        => array(
					'Converted the plugin into a standalone booking application with shortcode and Elementor widget embedding.',
					'Added app-owned step engine, hosted ChatKit assistant step, and top-level admin app.',
					'Retained local CRM, routing, Cal.com booking, and returning-client verification as internal services.',
				),
			),
			array(
				'version'      => '1.1.0',
				'date'         => '2026-04-14',
				'title'        => 'Migration Framework',
				'notes'        => array(
					'Added versioned migration support for future schema changes.',
					'Expanded draft requests with app step and app state tracking.',
				),
			),
		);
	}
}
