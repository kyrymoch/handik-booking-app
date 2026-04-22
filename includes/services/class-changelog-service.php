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
				'version'      => '2.0.57',
				'date'         => '2026-04-21',
				'title'        => 'Fit Virtual Assistant Into The Mobile Viewport',
				'notes'        => array(
					'Added a dedicated assistant screen class so the mobile Virtual assistant step can size itself against the available viewport instead of using the generic screen layout.',
					'Removed the hard inline ChatKit min-height and tied the hosted chat element to the available assistant host height so the title, intro, chat, fixed header offset, and bottom bar fit together more reliably on phones.',
				),
			),
			array(
				'version'      => '2.0.56',
				'date'         => '2026-04-21',
				'title'        => 'Normalize Step Scroll Position And Remove Horizontal Booking Overflow',
				'notes'        => array(
					'Added a smooth step-change scroll reset so each new screen returns to a consistent top anchor with an offset for fixed site headers.',
					'Locked the booking app shell, screen body, and Cal.com embed containers to full width with horizontal overflow clipping so the booking step no longer creates sideways scrolling.',
				),
			),
			array(
				'version'      => '2.0.55',
				'date'         => '2026-04-21',
				'title'        => 'Add Deep Twilio Verify Diagnostics For Returning Client SMS',
				'notes'        => array(
					'Added detailed log entries for returning-client code requests, including whether the contact was found, which delivery channel was selected, and whether Twilio Verify was configured.',
					'Logged Twilio Verify request start, HTTP response status, Twilio status/SID, and Twilio error fields so SMS delivery issues can be diagnosed directly from the admin Logs screen.',
					'Added matching verification-check logs so it is visible whether the code check failed before or after reaching Twilio.',
				),
			),
			array(
				'version'      => '2.0.54',
				'date'         => '2026-04-21',
				'title'        => 'Add Twilio Verify For Returning Client Phone Codes',
				'notes'        => array(
					'Added Twilio Verify integration settings for Account SID, Auth Token, and Verify Service SID in the Integrations admin screen.',
					'Switched phone-based returning-client verification to Twilio Verify so SMS code delivery and code checks now use the configured `VA...` service instead of the previously empty SMS hook path.',
					'Kept the local email verification flow in place so returning clients can still sign in by email even if they do not use phone verification.',
				),
			),
			array(
				'version'      => '2.0.53',
				'date'         => '2026-04-20',
				'title'        => 'Tighten Selected Tasks Bounce And Remove The Extra Assistant Loader Layer',
				'notes'        => array(
					'Adjusted the Selected tasks entrance motion so the sheet appears first and then performs a quick up-down-up return before settling in place.',
					'Normalized older saved Virtual assistant strings to the new shorter greeting and clearer `Book a time` footer action label.',
					'Removed the bridge-side loading placeholder so the Virtual assistant step now relies on a single loading animation while hosted ChatKit initializes.',
				),
			),
			array(
				'version'      => '2.0.52',
				'date'         => '2026-04-20',
				'title'        => 'Refine Selected Tasks Entrance Motion And Address Unit Formatting',
				'notes'        => array(
					'The Selected tasks panel now bounces only when it first appears after the selection count goes from zero tasks to one, instead of animating again on every later task change.',
					'If all tasks are removed the Selected tasks panel disappears completely, and the one-time bounce is re-armed for the next first task that gets added later.',
					'Admin booking addresses now append apartment data as `Apt ...`, and the Virtual assistant bridge loader spacing has been aligned more closely with the rest of the app loaders.',
				),
			),
			array(
				'version'      => '2.0.51',
				'date'         => '2026-04-20',
				'title'        => 'Improve Phone Entry, Selected Tasks Motion, And Assistant Readiness Feedback',
				'notes'        => array(
					'Stopped rewriting the phone field on every keystroke so customers can type naturally, while validation and API submission still normalize the number into the expected backend format.',
					'Added a bounce-style entrance animation for the Selected tasks sheet so the collapsed panel is more noticeable when the client picks tasks.',
					'Updated the Virtual assistant fallback copy, included Unit or apartment in the admin booking address summary, and dismiss the assistant loading overlay as soon as the chat session is actually ready.',
				),
			),
			array(
				'version'      => '2.0.50',
				'date'         => '2026-04-20',
				'title'        => 'Stabilize Virtual Assistant Mount And Tighten The Loadbar Animation',
				'notes'        => array(
					'Matched the loading animation more closely to the provided `sp-loadbar` reference by restoring the original stripe angle, spacing, and plain `Loading` label.',
					'Removed the startup ChatKit client-tool probe so the hosted assistant no longer fires an extra tool request while the widget is still mounting.',
					'Stopped the assistant preparation flow from forcing a full screen re-render during mount, which fixes the white-screen refresh loop on the Virtual assistant step.',
				),
			),
			array(
				'version'      => '2.0.49',
				'date'         => '2026-04-20',
				'title'        => 'Stabilize Virtual Assistant Loading And Match The Requested Loadbar Loader',
				'notes'        => array(
					'Replaced the frontend and bridge loading visuals with the exact `sp-loadbar` style and the plain `Loading` label from the provided loader reference file.',
					'Restored a safer Virtual assistant loading lifecycle so the assistant overlay is attached directly to the chat screen body without forcing extra re-renders during mount.',
					'Separated the assistant-specific loading overlay from the generic screen overlay so hosted ChatKit can finish mounting without falling into a white-screen or full-page freeze loop.',
				),
			),
			array(
				'version'      => '2.0.48',
				'date'         => '2026-04-20',
				'title'        => 'Stabilize App Setup Persistence And Refresh Admin And Frontend UX',
				'notes'        => array(
					'Fixed App Setup persistence so saving one tab now merges into the existing settings set instead of resetting other tabs back to defaults, which also stops Integrations from being wiped by unrelated saves.',
					'Moved Integrations into a dedicated admin section with Logs and Changelog, redesigned the Bookings list and booking detail view around client-first booking summaries, and standardized booking times to Eastern Time formatting.',
					'Updated the booking UI with quieter task selection, cleaner Photos and Contact details screens, new footer button styling controls, a lighter shell that can inherit Elementor styling, loadbar-based loading states, and faster large mobile photo uploads through client-side image downscaling.',
				),
			),
			array(
				'version'      => '2.0.47',
				'date'         => '2026-04-20',
				'title'        => 'Bind Hosted ChatKit Client Tools On The Element And Add Runtime Probes',
				'notes'        => array(
					'The hosted ChatKit bridge now binds `get_request_photo_context` both through `options.onClientTool` and directly on the mounted element as `element.onClientTool`, to cover runtimes that only honor property-level callbacks on the live web component.',
					'Added explicit info-level logs for client-tool invocation, fetch start, fetch completion, payload return, and a browser-side tool probe so it is immediately visible whether the runtime is capable of completing the tool call before the user sends a message.',
					'Client-tool failures now return a safe JSON fallback payload instead of leaving the workflow tool call with no output, which should prevent the assistant from stalling indefinitely at the Classification Agent.',
				),
			),
			array(
				'version'      => '2.0.46',
				'date'         => '2026-04-20',
				'title'        => 'Re-Apply Hosted ChatKit Client Tool Options After Mount',
				'notes'        => array(
					'The ChatKit bridge now applies options through both the element options property and setOptions(), and repeats that application after the hosted widget is attached to the DOM so client-tool callbacks are present on the live element.',
					'Added a bridge diagnostic for ChatKit option application with onClientTool enabled, which should make it obvious whether the browser-side tool handler is actually registered before the first workflow tool call arrives.',
					'This is a focused hotfix for the hosted get_request_photo_context tool path, where the workflow was calling the tool but the browser handler was not firing and the chat stalled at the Classification Agent.',
				),
			),
			array(
				'version'      => '2.0.45',
				'date'         => '2026-04-19',
				'title'        => 'Switch Uploaded Photo Context To A Hosted ChatKit Client Tool',
				'notes'        => array(
					'Added a dedicated request-photo-context REST endpoint so the hosted assistant can retrieve current uploaded-photo analysis directly from WordPress instead of waiting for fragile hidden context messages to land in the thread.',
					'The ChatKit bridge now handles the client tool `get_request_photo_context`, forwards the current request and draft token to WordPress, and returns the photo-context payload back into the workflow as a supported hosted tool result.',
					'Removed the old HANDIK_CONTEXT thread-dispatch dependency from the assistant gate and kept the pre-chat warmup only as a preparation step, so the chat no longer depends on hidden thread injection before the user can type.',
				),
			),
			array(
				'version'      => '2.0.44',
				'date'         => '2026-04-19',
				'title'        => 'Send Photo Context Even When Analysis Is Inconclusive Or Failed',
				'notes'        => array(
					'Photo-analysis failure and inconclusive paths now persist a complete photo_analysis object with a stable signature instead of leaving the assistant gate with an empty photos_signature.',
					'The assistant photo-context dispatcher now uses a fallback dispatch signature when needed, so HANDIK_CONTEXT can still be sent into the live thread even when uploaded-photo analysis is limited or unavailable.',
					'This closes the silent skip where the assistant unlocked normally but never dispatched any uploaded-photo context simply because the stored analysis object did not carry a usable signature.',
				),
			),
			array(
				'version'      => '2.0.43',
				'date'         => '2026-04-19',
				'title'        => 'Fire Assistant Session Readiness From Real Interactive Chat Signals',
				'notes'        => array(
					'The ChatKit bridge now triggers its session-ready callback from the first real interactive chat signal, not only from the flaky chatkit.ready event, so the assistant photo gate can continue even when the hosted widget loads silently in the background.',
					'Added a dedicated bridge log for the session-ready callback source and a timeout fallback that forces session readiness after an interactive mount if the hosted ready event still never arrives.',
					'This prevents the Virtual assistant overlay from hanging forever simply because ChatKit became usable without emitting the exact ready event the booking app was waiting on.',
				),
			),
			array(
				'version'      => '2.0.42',
				'date'         => '2026-04-19',
				'title'        => 'Gate The Virtual Assistant Until Photo Context Reaches The Thread',
				'notes'        => array(
					'The Virtual assistant step now stays blocked behind its loading overlay until uploaded-photo analysis has been prepared and the HANDIK_CONTEXT message has been dispatched into the live ChatKit thread.',
					'Added assistant photo-gate logs for gate start, analysis readiness, context dispatch start/completion/failure, and composer unlock so it is clear whether the thread received visual context before the customer can type.',
					'Removed the old timing gap where ChatKit could become interactive before photo context was injected, which let the first user question reach OpenAI without the uploaded-photo summary.',
				),
			),
			array(
				'version'      => '2.0.41',
				'date'         => '2026-04-19',
				'title'        => 'Fix Services & Categories Saving And Replace The Catalog With The New Service Map',
				'notes'        => array(
					'The Services & Categories editor now saves reliably because dynamic catalog fields are renumbered on the admin side and the backend can rebuild the catalog directly from the submitted form fields instead of depending only on the hidden JSON payload.',
					'Replaced the old starter catalog with the new eight-category Handik service map covering Assembly, Plumbing, Electrical, Interior Finishes, Doors and Windows, Carpentry, Exterior work, and Not Sure / Project Help.',
					'Added a legacy-catalog detector so older installs that still point at the original built-in service set automatically fall forward to the new default catalog structure instead of staying stuck on the outdated groups.',
				),
			),
			array(
				'version'      => '2.0.40',
				'date'         => '2026-04-19',
				'title'        => 'Stabilize Intake Screens And Make Photo Analysis The Only Production Photo Path',
				'notes'        => array(
					'Removed the old info-mode toggle and simplified booking-app notifications to always-on error, warning, and info messages, while dropping the older success and task-specific toast types.',
					'Address details now require a real Google-suggested address before Continue activates, Contact details validate full name, email, and phone formats live, and the task step now includes a sticky Selected tasks bottom sheet with descriptions and hourly rates.',
					'The Virtual assistant step no longer depends on hosted ChatKit file handoff attempts; uploaded photos are saved to WordPress, queued for backend photo analysis, and the assistant waits on the stable photo-analysis path instead of native chat file injection.',
				),
			),
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
