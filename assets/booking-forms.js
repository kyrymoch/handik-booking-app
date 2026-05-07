/**
 * Public SPA for the Additional Booking Forms module.
 *
 * Two flows are supported, picked by the preset's `form_type`:
 *
 *   1. direct_cal_booking   Standard / Extended / Large Visits.
 *      Contact → Address → Cal.com inline embed (one slot).
 *
 *   2. project_work_days    Larger-scale project schedules (2-N days).
 *      Contact → Address → Multi-day picker → Review → Confirm
 *      (server then creates N separate Cal.com bookings via the v2 API).
 *
 * Visual contract: this file deliberately reuses the EXACT DOM classes and
 * structure of `booking-app.js` (`.handik-booking-app__shell`,
 * `.handik-booking-app__screen`, `.handik-booking-app__screen-header`,
 * `.handik-field`, `.handik-btn`, `.handik-footer-actions`,
 * `.handik-progress-dots`, `.handik-app-disclaimer`, `.handik-toast`,
 * `.handik-booking-app__booking-embed`, etc.) so the public stylesheet —
 * `booking-app.css` — themes both forms with one set of design tokens.
 * Keep this contract intact when editing.
 *
 * Notifications, Cal embed loader, Google Maps Places autocomplete, sticky
 * footer with Back/Continue, the inline phone formatter, the saved-address
 * <select> dropdown, the progress dots, and the "Stuck? Start a new booking"
 * disclaimer all mirror the main app pixel-for-pixel.
 *
 * Bootstrap runs on DOMContentLoaded so any markup the theme injects after
 * the script tag is already present when we look it up.
 */
( function ( window, document ) {
	'use strict';

	var GOOGLE_SCRIPT_ID     = 'handik-google-maps-places';
	var CAL_EMBED_SCRIPT_ID  = 'handik-cal-embed-script';
	var CAL_EMBED_TIMEOUT_MS = 15000;
	var TOAST_DURATION_MS    = 4200;

	// Draft persistence — namespaced separately from the main app's draft so
	// the two can coexist on the same site without colliding.
	var DRAFT_STORAGE_KEY_PREFIX = 'handik_booking_form_draft_v1_';
	var DRAFT_TTL_MS             = 24 * 60 * 60 * 1000; // 24 hours.
	var DRAFT_DEBOUNCE_MS        = 500;
	// State fields that round-trip through localStorage. Keep this list
	// minimal and explicit — anything not on it doesn't survive a refresh.
	var PERSISTED_STATE_FIELDS = [
		'step',
		'contact',
		'address',
		'directRequestId',
		'directCaptureToken',
		'calBookingUrl',
		'scheduleId',
		'publicToken',
		'selectedSlots',
		'isReturningClient',
		'profileContactId',
		'savedAddresses'
	];

	// ===================================================================
	// Constructor + lifecycle
	// ===================================================================

	function HandikBookingForm( root ) {
		this.root = root;
		this.config      = parseConfig( root );
		this.preset      = this.config.preset || {};
		this.i18n        = this.config.i18n || {};
		this.formType    = String( this.preset.form_type || '' );
		this.isProject   = 'project_work_days' === this.formType;
		this.requiredDays = parseInt( this.preset.required_days, 10 ) || 0;
		this.timezone    = this.config.timezone || 'America/New_York';

		this.instanceId          = 'handik-form-' + Math.random().toString( 36 ).slice( 2, 8 );
		this.notificationRootId  = this.instanceId + '-notifications';
		this.notificationTimers  = new Map();
		this.notificationCounter = 0;

		this.calEmbedPromise   = null;
		this.calEmbedNamespace = '';

		this.googleMapsPromise   = null;
		this.addressAutocomplete = null;

		// Phone-based contact lookup state. We hit /contacts/lookup once per
		// unique fully-typed phone number and cache the result so leaving and
		// re-entering the contact step doesn't spam the endpoint.
		this.lookupInFlightPhone = '';
		this.lookupLastPhone     = '';

		this.state = {
			// Sprint 5: phone-first flow. The customer types a phone, gets a
			// Twilio OTP, then continues to a slim "details" screen (name +
			// email + address for new clients; saved-address picker + address
			// for returning clients). The legacy 'contact' step is gone.
			step: 'phone',          // phone | otp | details | cal | pick-days | review-days | success
			busy: false,
			contact: { full_name: '', phone: '', email: '' },
			otpCode: '',
			verifiedPhone: '',
			verifiedToken: '',
			otpResendDisabledUntil: 0, // ms epoch — gates the "Resend" button
			otpError: '',
			address: {
				address_id: 0,
				address_full: '',
				address_unit: '',
				address_line_1: '',
				city: '',
				state: '',
				zip_code: '',
				is_valid: false
			},
			touched: {},
			directRequestId: 0,
			directCaptureToken: '',
			_captureSent: false,
			calBookingUrl: '',
			scheduleId: 0,
			publicToken: '',
			slots: [],
			slotsLoaded: false,
			selectedSlots: [],
			confirmedDays: [],
			savedAddresses: [],   // populated by /contacts/lookup
			isReturningClient: false,
			profileContactId: 0,
			restartConfirmVisible: false
		};

		this.applyAppearance();
		this.ensureNotificationsRoot();
		this.attachHistoryListener();
		// Restore from localStorage BEFORE the initial render so the SPA
		// boots straight into the saved step + form values. The plugin
		// version is part of the envelope so a plugin upgrade invalidates
		// stale drafts (avoids replaying state across breaking changes).
		this.restoreDraftFromStorage();
		this.replaceHistoryState( this.state.step );
		// First render shows whatever step the draft restored (or `phone`).
		this.render();
		// Sprint 5: if the customer has a verified-client token from a
		// previous session, revalidate it and skip the OTP step. Async —
		// the form already rendered to phone-step; on success we'll
		// re-render straight to `details`.
		var self = this;
		this.tryRestoreVerifiedClient().then( function ( restored ) {
			if ( restored ) { self.render(); }
		} );
	}

	// ===================================================================
	// Draft persistence (localStorage)
	// ===================================================================

	HandikBookingForm.prototype.draftStorageKey = function () {
		// Per-preset namespace so a customer who's mid-flow on one
		// /booking/{slug} doesn't see their data hop to a different
		// preset they then opened.
		return DRAFT_STORAGE_KEY_PREFIX + ( this.preset && this.preset.preset_slug ? String( this.preset.preset_slug ) : 'default' );
	};

	HandikBookingForm.prototype.saveDraftToStorage = function () {
		if ( ! window.localStorage ) { return; }
		// Don't persist after the customer has confirmed — clearDraftStorage
		// runs on success / restart paths, but be defensive here too.
		if ( 'success' === this.state.step ) { return; }
		var persisted = {};
		var self = this;
		PERSISTED_STATE_FIELDS.forEach( function ( key ) {
			if ( Object.prototype.hasOwnProperty.call( self.state, key ) ) {
				persisted[ key ] = self.state[ key ];
			}
		} );
		var envelope = {
			version: this.config.version || '0',
			savedAt: Date.now(),
			state: persisted
		};
		try {
			window.localStorage.setItem( this.draftStorageKey(), JSON.stringify( envelope ) );
		} catch ( e ) {
			// Quota exceeded or storage disabled — silently ignore.
		}
	};

	HandikBookingForm.prototype.restoreDraftFromStorage = function () {
		if ( ! window.localStorage ) { return; }
		var raw;
		try {
			raw = window.localStorage.getItem( this.draftStorageKey() );
		} catch ( e ) { return; }
		if ( ! raw ) { return; }
		var envelope;
		try {
			envelope = JSON.parse( raw );
		} catch ( e ) {
			this.clearDraftStorage();
			return;
		}
		if ( ! envelope || 'object' !== typeof envelope || ! envelope.state ) {
			this.clearDraftStorage();
			return;
		}
		// Invalidate on plugin upgrades — schemas may have shifted.
		if ( this.config.version && envelope.version !== this.config.version ) {
			this.clearDraftStorage();
			return;
		}
		// TTL check.
		if ( ! envelope.savedAt || Date.now() - envelope.savedAt > DRAFT_TTL_MS ) {
			this.clearDraftStorage();
			return;
		}
		// Don't restore terminal states — they shouldn't have been saved
		// but be defensive in case a previous build did.
		if ( 'success' === envelope.state.step ) {
			this.clearDraftStorage();
			return;
		}
		var self = this;
		PERSISTED_STATE_FIELDS.forEach( function ( key ) {
			if ( Object.prototype.hasOwnProperty.call( envelope.state, key ) ) {
				self.state[ key ] = envelope.state[ key ];
			}
		} );
	};

	HandikBookingForm.prototype.clearDraftStorage = function () {
		if ( ! window.localStorage ) { return; }
		try {
			window.localStorage.removeItem( this.draftStorageKey() );
		} catch ( e ) { /* ignore */ }
	};

	/**
	 * Debounced save. Called from input handlers + go() so we don't write
	 * to localStorage on every keystroke.
	 */
	HandikBookingForm.prototype.scheduleDraftSave = function () {
		var self = this;
		if ( this._draftSaveTimer ) {
			window.clearTimeout( this._draftSaveTimer );
		}
		this._draftSaveTimer = window.setTimeout( function () {
			self._draftSaveTimer = null;
			self.saveDraftToStorage();
		}, DRAFT_DEBOUNCE_MS );
	};

	// ===================================================================
	// Browser history (back/forward navigation between form steps)
	// ===================================================================

	/**
	 * Listen once for popstate events. When the customer hits the browser
	 * back button, we navigate to the previous step instead of leaving the
	 * page. State entries are stamped with our `instanceId` so we don't
	 * react to popstate from other apps on the same page.
	 *
	 * Mirrors the main app's identical implementation.
	 */
	HandikBookingForm.prototype.attachHistoryListener = function () {
		if ( this._popstateBound ) {
			return;
		}
		this._popstateBound = true;
		var self = this;
		window.addEventListener( 'popstate', function ( event ) {
			var data = event && event.state ? event.state : null;
			if ( ! data || ! data.handikBookingForm || data.instanceId !== self.instanceId ) {
				return;
			}
			if ( ! data.step || data.step === self.state.step ) {
				return;
			}
			self._navigatingFromHistory = true;
			self.go( data.step );
			self._navigatingFromHistory = false;
		} );
	};

	HandikBookingForm.prototype.replaceHistoryState = function ( step ) {
		if ( ! window.history || 'function' !== typeof window.history.replaceState ) {
			return;
		}
		try {
			window.history.replaceState(
				{ handikBookingForm: true, instanceId: this.instanceId, step: step },
				'',
				window.location.href
			);
		} catch ( e ) {
			// SecurityError on file:// or some restricted iframes — fail silently.
		}
	};

	HandikBookingForm.prototype.pushHistoryState = function ( step ) {
		if ( this._navigatingFromHistory ) {
			return;
		}
		if ( ! window.history || 'function' !== typeof window.history.pushState ) {
			return;
		}
		try {
			window.history.pushState(
				{ handikBookingForm: true, instanceId: this.instanceId, step: step },
				'',
				window.location.href
			);
		} catch ( e ) {
			// Ignore — history is best-effort.
		}
	};

	HandikBookingForm.prototype.applyAppearance = function () {
		var vars = this.config.appearance || {};
		var keys = Object.keys( vars );
		// Apply CSS variables to the form's root so the same tokens the main
		// app uses theme this form too.
		for ( var i = 0; i < keys.length; i++ ) {
			try { this.root.style.setProperty( keys[ i ], vars[ keys[ i ] ] ); }
			catch ( e ) { /* ignore unsupported var name */ }
		}
		// Add the main-app root class so booking-app.css rules apply.
		this.root.classList.add( 'handik-booking-app' );
	};

	HandikBookingForm.prototype.ensureNotificationsRoot = function () {
		// Notification root — matches main app placement (fixed bottom-right).
		var existing = document.getElementById( this.notificationRootId );
		if ( existing ) {
			this.notificationsRoot = existing;
			return;
		}
		var notifications = document.createElement( 'div' );
		notifications.id = this.notificationRootId;
		notifications.className = 'handik-booking-app__notifications';
		// Landmark for assistive tech. role="region" + aria-label gives the
		// stack a name screen readers can navigate to. aria-live remains so
		// individual toast appends are announced when added by the SPA.
		notifications.setAttribute( 'role', 'region' );
		notifications.setAttribute( 'aria-label', this.t( 'notificationsRegionLabel' ) || 'Notifications' );
		notifications.setAttribute( 'aria-live', 'polite' );
		document.body.appendChild( notifications );
		this.notificationsRoot = notifications;
	};

	// ===================================================================
	// Top-level render
	// ===================================================================

	/**
	 * Replace the inner shell content with the current step's markup.
	 * The structure mirrors the main app exactly:
	 *
	 *   <section class="handik-booking-app__screen handik-booking-app__screen--<step>">
	 *     <div class="handik-booking-app__screen-header"><h2>{step title}</h2></div>
	 *     <div class="handik-booking-app__screen-body">{step body}</div>
	 *   </section>
	 *   <div class="handik-global-progress">{progress dots}</div>
	 *   <aside class="handik-app-disclaimer">{stuck links}</aside>
	 */
	HandikBookingForm.prototype.render = function () {
		var shell = this.root.querySelector( '[data-handik-booking-form-shell]' );
		if ( ! shell ) {
			shell = document.createElement( 'div' );
			shell.setAttribute( 'data-handik-booking-form-shell', '' );
			this.root.appendChild( shell );
		}
		// Reset the shell class so step modifiers don't accumulate across renders.
		var stepSlug = this.state.step.replace( /[^a-z0-9_-]/gi, '-' );
		shell.className = 'handik-booking-app__shell handik-booking-app__shell--' + stepSlug;
		this.shell = shell;

		var stepClass = 'handik-booking-app__screen--' + stepSlug;
		var html = '';
		html += '<section class="handik-booking-app__screen ' + stepClass + '">';
		html +=   '<div class="handik-booking-app__screen-header"><h2>' + escapeHtml( this.stepTitle() ) + '</h2></div>';
		html +=   '<div class="handik-booking-app__screen-body">' + this.stepBody() + '</div>';
		html += '</section>';
		html += this.progressMarkup();
		html += this.disclaimerMarkup();
		html += this.restartModalMarkup();

		this.shell.innerHTML = html;

		this.bindEvents();
		this.afterRender();
	};

	HandikBookingForm.prototype.afterRender = function () {
		// Note: we deliberately do NOT move focus to the new <h2> on step
		// transitions — same parity decision the main form made (it was
		// dismissing mobile keyboards and confusing screen magnifiers).
		if ( 'address' === this.state.step ) {
			this.mountAddressAutocomplete();
		}
		if ( 'cal' === this.state.step ) {
			// Idempotent per DOM node: mountCalEmbed checks the container's
			// `data-handik-cal-mounted` attribute so re-renders inside the
			// cal step don't re-mount, but a step-change-and-return (which
			// rebuilds the container) re-mounts cleanly.
			this.mountCalEmbed();
		}
	};

	HandikBookingForm.prototype.stepTitle = function () {
		switch ( this.state.step ) {
			case 'phone':        return this.t( 'phoneStepTitle' );
			case 'otp':          return this.t( 'otpStepTitle' );
			case 'details':      return this.state.isReturningClient
				? this.t( 'detailsReturningTitle' )
				: this.t( 'detailsNewTitle' );
			case 'cal':          return this.t( 'calTitle' );
			case 'pick-days':    return this.t( 'pickDaysTitle' );
			case 'review-days':  return this.t( 'reviewTitle' );
			case 'success':      return this.t( 'successHeading' );
			default:             return String( this.preset.form_title || '' );
		}
	};

	HandikBookingForm.prototype.stepBody = function () {
		switch ( this.state.step ) {
			case 'phone':       return this.phoneStepMarkup();
			case 'otp':         return this.otpStepMarkup();
			case 'details':     return this.detailsStepMarkup();
			case 'cal':         return this.calMarkup();
			case 'pick-days':   return this.pickDaysMarkup();
			case 'review-days': return this.reviewDaysMarkup();
			case 'success':     return this.successMarkup();
			default:            return '<p>' + escapeHtml( this.t( 'genericError' ) ) + '</p>';
		}
	};

	// ===================================================================
	// Step renderers
	// ===================================================================

	HandikBookingForm.prototype.contactMarkup = function () {
		// Phone-only entry. Single field. Continue triggers Twilio Verify.
		var c = this.state.contact;
		var t = this.state.touched;
		var phoneError = t.phone && ! validatePhone( c.phone ) ? this.t( 'errorPhone' ) : '';
		return [
			'<p class="handik-booking-app__intro">', escapeHtml( this.t( 'phoneStepIntro' ) ), '</p>',
			this.fieldMarkup( {
				model: 'contact.phone',
				label: this.t( 'phoneLabel' ),
				type: 'tel',
				value: c.phone,
				autocomplete: 'tel',
				inputmode: 'tel',
				placeholder: this.t( 'phonePlaceholder' ),
				error: phoneError,
				required: true,
				inputId: 'handik-form-phone'
			} ),
			this.footerActionsMarkup( {
				continueAction: 'phone-next',
				continueLabel: this.t( 'sendCodeCta' ),
				hideBack: true,
				continueMuted: ! validatePhone( c.phone ) || this.state.busy
			} )
		].join( '' );
	};

	/**
	 * OTP step. 6-digit code entry, "Verify" CTA, "Resend" + "Use a different
	 * number" links. Resend is rate-limited locally with a 30s lockout the
	 * SPA enforces (the server also rate-limits but we want to keep the UI
	 * honest before the round-trip).
	 */
	HandikBookingForm.prototype.otpStepMarkup = function () {
		var error = this.state.otpError || '';
		var now = Date.now();
		var resendIn = Math.max( 0, Math.ceil( ( this.state.otpResendDisabledUntil - now ) / 1000 ) );

		return [
			'<p class="handik-booking-app__intro">',
				escapeHtml( ( this.t( 'otpIntro' ) || '' ).replace( '%s', this.state.contact.phone ) ),
			'</p>',
			this.fieldMarkup( {
				model: 'otpCode',
				label: this.t( 'otpCodeLabel' ),
				type: 'text',
				value: this.state.otpCode,
				autocomplete: 'one-time-code',
				inputmode: 'numeric',
				placeholder: this.t( 'otpPlaceholder' ),
				error: error,
				required: true,
				inputId: 'handik-form-otp',
				rawAttrs: 'maxlength="8" pattern="[0-9]*"'
			} ),
			'<div class="handik-booking-app__otp-aux">',
				resendIn > 0
					? '<span class="handik-booking-app__otp-resend is-pending">' +
						escapeHtml( ( this.t( 'otpResendIn' ) || '' ).replace( '%d', resendIn ) ) +
					'</span>'
					: '<button type="button" class="handik-text-link" data-action="otp-resend">' +
						escapeHtml( this.t( 'otpResendCta' ) ) +
					'</button>',
				'<span class="handik-app-disclaimer__sep" aria-hidden="true"> · </span>',
				'<button type="button" class="handik-text-link" data-action="otp-back">',
					escapeHtml( this.t( 'otpDifferentNumberCta' ) ),
				'</button>',
			'</div>',
			// Hotfix 2.1.13.1: Verify is now automatic — the moment the
			// customer types the 6th digit (or the SMS-autofill chip is
			// tapped) `onInput` calls `verifyPhoneOtp`. The button is hidden
			// to remove the dead-state UI ("can't tap, can't tell why")
			// reported by the owner.
			this.footerActionsMarkup( {
				hideBack: true,
				hideContinue: true
			} )
		].join( '' );
	};

	/**
	 * Combined details step. Renders different fields based on whether the
	 * verified phone matched an existing contact (returning) or not (new).
	 *
	 *   New     → Full name, Email, Address, Unit
	 *   Returning → Saved-address dropdown (when present), Address, Unit
	 *
	 * Address validation parity stays the same as the old `addressMarkup`.
	 */
	HandikBookingForm.prototype.detailsStepMarkup = function () {
		var c = this.state.contact;
		var a = this.state.address;
		var t = this.state.touched;
		var hasMaps = !! this.config.googleMapsApiKey;
		var addressFilled = '' !== String( a.address_full || '' ).trim();
		var continueMuted = hasMaps ? ! a.is_valid : ! addressFilled;
		var addressError = ( hasMaps && addressFilled && ! a.is_valid )
			? this.t( 'errorAddressInvalid' )
			: '';
		var nameError = t.full_name && ! validateFullName( c.full_name ) ? this.t( 'errorRequired' ) : '';
		var emailError = t.email && '' !== c.email && ! validateEmail( c.email ) ? this.t( 'errorEmail' ) : '';

		// New-client requires a name; returning has it from the verified profile.
		if ( ! this.state.isReturningClient ) {
			continueMuted = continueMuted || ! validateFullName( c.full_name );
			if ( '' !== c.email && ! validateEmail( c.email ) ) {
				continueMuted = true;
			}
		}

		// New-client name + email block (skipped for returning).
		var newClientFields = '';
		if ( ! this.state.isReturningClient ) {
			newClientFields =
				this.fieldMarkup( {
					model: 'contact.full_name',
					label: this.t( 'fullNameLabel' ),
					type: 'text',
					value: c.full_name,
					autocomplete: 'name',
					placeholder: this.t( 'fullNamePlaceholder' ),
					error: nameError,
					required: true,
					inputId: 'handik-form-full-name'
				} ) +
				this.fieldMarkup( {
					model: 'contact.email',
					label: this.t( 'emailLabel' ),
					type: 'email',
					value: c.email,
					autocomplete: 'email',
					inputmode: 'email',
					placeholder: this.t( 'emailPlaceholder' ),
					error: emailError,
					required: false,
					inputId: 'handik-form-email'
				} );
		}

		// Returning-client saved-address dropdown (if any).
		var savedMarkup = '';
		var savedAddresses = Array.isArray( this.state.savedAddresses ) ? this.state.savedAddresses : [];
		if ( this.state.isReturningClient && savedAddresses.length ) {
			var options = '<option value="">' + escapeHtml( this.t( 'savedAddressPlaceholder' ) ) + '</option>';
			savedAddresses.forEach( function ( addr ) {
				var line = String( addr.address_full || addr.address_line_1 || '' ).trim();
				options += '<option value="' + escapeAttr( String( addr.id ) ) + '"' +
					( a.address_id && parseInt( addr.id, 10 ) === parseInt( a.address_id, 10 ) ? ' selected' : '' ) +
					'>' + escapeHtml( line ) + '</option>';
			} );
			savedMarkup = '<label class="handik-field" for="handik-form-saved-address">' +
				'<span>' + escapeHtml( this.t( 'savedAddressLabel' ) ) + '</span>' +
				'<select id="handik-form-saved-address" autocomplete="off">' + options + '</select>' +
			'</label>';
		} else if ( this.state.isReturningClient ) {
			savedMarkup = '<p class="handik-field__help" role="status">' + escapeHtml( this.t( 'savedAddressEmpty' ) ) + '</p>';
		}

		// Native autofill suppression for the address line — same as before.
		var addressAttrs = hasMaps
			? 'autocomplete="new-password" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-form-type="other" name="handik_form_location_query"'
			: 'autocomplete="street-address" name="handik_form_location_query"';
		var unitAttrs = 'autocomplete="new-password" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-form-type="other" name="handik_form_unit_detail"';

		var intro = this.state.isReturningClient
			? ( this.t( 'detailsReturningIntro' ) || '' ).replace( '%s', this.state.contact.full_name || this.state.verifiedPhone )
			: this.t( 'detailsNewIntro' );

		return [
			intro ? '<p class="handik-booking-app__intro">' + escapeHtml( intro ) + '</p>' : '',
			newClientFields,
			savedMarkup,
			this.fieldMarkup( {
				model: 'address.address_full',
				label: this.t( 'addressLabel' ),
				type: 'text',
				value: a.address_full,
				placeholder: this.t( 'addressPlaceholder' ),
				inputId: 'handik-form-address',
				error: addressError,
				required: true,
				rawAttrs: addressAttrs,
				skipName: true,
				fieldExtraClass: ( hasMaps && addressFilled && ! a.is_valid ) ? ' is-invalid' : ''
			} ),
			this.fieldMarkup( {
				model: 'address.address_unit',
				label: this.t( 'unitLabel' ),
				type: 'text',
				value: a.address_unit,
				placeholder: this.t( 'unitPlaceholder' ),
				inputId: 'handik-form-address-unit',
				required: false,
				rawAttrs: unitAttrs,
				skipName: true
			} ),
			this.footerActionsMarkup( {
				backAction: 'details-back',
				continueAction: 'details-next',
				continueLabel: this.t( 'continueLabel' ),
				continueMuted: continueMuted
			} )
		].join( '' );
	};

	// Legacy entry point kept so render() doesn't crash if any old code path
	// still calls these names. They route to the new step renderers.
	// Alias the legacy `contactMarkup` to the new phone step entrypoint.
	// `phoneStepMarkup` is the canonical name; `contactMarkup` survives as
	// an alias only for any external code that referenced it (none in-tree
	// today — kept as a safety net during the cutover).
	HandikBookingForm.prototype.phoneStepMarkup = HandikBookingForm.prototype.contactMarkup;

	HandikBookingForm.prototype.calMarkup = function () {
		// Skeleton matches main app's `.handik-skeleton--calendar`.
		var skeleton = '<div class="handik-skeleton handik-skeleton--calendar" aria-hidden="true">' +
			'<div class="handik-skeleton__bar handik-skeleton__bar--header"></div>' +
			'<div class="handik-skeleton__grid">' +
				new Array( 9 ).join( '<div class="handik-skeleton__cell"></div>' ) + '<div class="handik-skeleton__cell"></div>' +
			'</div></div>';

		// Cal step is the FINAL step in the direct flow — same as the main
		// app's `booking` step. No Back/Continue footer; the customer either
		// books inside the iframe (success comes from the Cal webhook +
		// captureDirectBooking handler) or uses the Stuck disclaimer below.
		return [
			'<p class="handik-booking-app__intro">', escapeHtml( this.t( 'calIntro' ) ), '</p>',
			'<div class="handik-booking-app__booking-embed" data-handik-cal-embed>', skeleton, '</div>'
		].join( '' );
	};

	HandikBookingForm.prototype.pickDaysMarkup = function () {
		if ( ! this.state.slotsLoaded ) {
			return this.loadingMarkup( this.t( 'loading' ) ) +
				this.footerActionsMarkup( {
					backAction: 'pick-back',
					continueAction: 'pick-next',
					continueLabel: this.t( 'continueLabel' ),
					continueMuted: true
				} );
		}
		if ( ! this.state.slots.length ) {
			return [
				'<div class="handik-booking-app__alert is-error" role="alert">',
					escapeHtml( this.t( 'noSlots' ) ),
				'</div>',
				this.footerActionsMarkup( {
					backAction: 'pick-back',
					hideContinue: true
				} )
			].join( '' );
		}

		var grouped = groupSlotsByDay( this.state.slots, this.timezone );
		var selectedKeys = {};
		this.state.selectedSlots.forEach( function ( s ) { selectedKeys[ s.start ] = true; } );
		var canSelectMore = this.state.selectedSlots.length < this.requiredDays;

		var html = '';
		html += '<p class="handik-booking-app__intro">' + escapeHtml( ( this.t( 'pickHelper' ) || '' ).replace( '%d', this.requiredDays ) ) + '</p>';
		html += '<ul class="handik-booking-app__slots">';
		grouped.forEach( function ( day ) {
			html += '<li class="handik-booking-app__slot-day">';
			html += '<div class="handik-booking-app__slot-day-label">' + escapeHtml( day.label ) + '</div>';
			html += '<div class="handik-booking-app__slot-row">';
			day.slots.forEach( function ( slot ) {
				var isSelected = !! selectedKeys[ slot.start_iso ];
				var disabled   = ! isSelected && ! canSelectMore;
				var pressed = isSelected ? 'true' : 'false';
				var cls = 'handik-booking-app__slot' + ( isSelected ? ' is-selected' : '' );
				html += '<button type="button" class="' + cls + '"';
				html += ' data-action="toggle-slot"';
				html += ' data-slot-start="' + escapeAttr( slot.start_iso ) + '"';
				html += ' data-slot-end="' + escapeAttr( slot.end_iso || '' ) + '"';
				html += ' aria-pressed="' + pressed + '"';
				if ( disabled ) { html += ' disabled aria-disabled="true"'; }
				html += '>' + escapeHtml( slot.timeLabel ) + '</button>';
			} );
			html += '</div></li>';
		} );
		html += '</ul>';

		var counter = ( this.t( 'selectionCounter' ) || '' )
			.replace( '%1$d', this.state.selectedSlots.length )
			.replace( '%2$d', this.requiredDays );
		html += '<p class="handik-booking-app__slot-counter" aria-live="polite"><strong>' + escapeHtml( counter ) + '</strong></p>';

		html += this.footerActionsMarkup( {
			backAction: 'pick-back',
			continueAction: 'pick-next',
			continueLabel: this.t( 'continueLabel' ),
			continueMuted: this.state.selectedSlots.length !== this.requiredDays
		} );
		return html;
	};

	HandikBookingForm.prototype.reviewDaysMarkup = function () {
		var c = this.state.contact;
		var a = this.state.address;
		var html = '';
		html += '<p class="handik-booking-app__intro">' + escapeHtml( this.t( 'reviewIntro' ) ) + '</p>';
		html += '<dl class="handik-booking-app__review">';
		html += '<dt>' + escapeHtml( this.t( 'fullNameLabel' ) ) + '</dt><dd>' + escapeHtml( c.full_name ) + '</dd>';
		html += '<dt>' + escapeHtml( this.t( 'phoneLabel' ) ) + '</dt><dd>' + escapeHtml( c.phone ) + '</dd>';
		if ( c.email ) {
			html += '<dt>' + escapeHtml( this.t( 'emailLabel' ) ) + '</dt><dd>' + escapeHtml( c.email ) + '</dd>';
		}
		html += '<dt>' + escapeHtml( this.t( 'addressLabel' ) ) + '</dt><dd>' + escapeHtml( a.address_full + ( a.address_unit ? ', ' + a.address_unit : '' ) ) + '</dd>';
		html += '</dl>';
		html += '<h3 class="handik-booking-app__review-heading">' + escapeHtml( this.t( 'reviewSelectedDaysHeading' ) ) + '</h3>';
		html += '<ol class="handik-booking-app__review-days">';
		var tz = this.timezone;
		this.state.selectedSlots.forEach( function ( s ) {
			html += '<li>' + escapeHtml( formatSlotLabelET( s.start, s.end || '', tz ) ) + '</li>';
		} );
		html += '</ol>';
		html += this.footerActionsMarkup( {
			backAction: 'review-back',
			continueAction: 'review-confirm',
			continueLabel: this.t( 'confirmCta' ),
			continueMuted: this.state.busy
		} );
		return html;
	};

	HandikBookingForm.prototype.successMarkup = function () {
		var msg = this.isProject ? this.t( 'projectSuccess' ) : this.t( 'directSuccess' );
		var html = '<div class="handik-booking-app__success-card"><strong>' + escapeHtml( this.t( 'successTitle' ) ) + '</strong>';
		html += escapeHtml( msg ) + '</div>';
		if ( this.isProject && this.state.confirmedDays.length ) {
			html += '<ul class="handik-booking-app__success-days">';
			var tz = this.timezone;
			this.state.confirmedDays.forEach( function ( s ) {
				html += '<li>' + escapeHtml( formatSlotLabelET( s.start, s.end || '', tz ) ) + '</li>';
			} );
			html += '</ul>';
		}
		return html;
	};

	HandikBookingForm.prototype.loadingMarkup = function ( label ) {
		return '<div class="handik-booking-app__loading" aria-live="polite">' +
			'<div class="sp sp-loadbar" aria-hidden="true"></div>' +
			'<h5>' + escapeHtml( label ) + '</h5>' +
		'</div>';
	};

	// ===================================================================
	// Progress dots + footer disclaimer (parity with main app)
	// ===================================================================

	HandikBookingForm.prototype.applicableSteps = function () {
		// Sprint 5: phone+OTP collapse to a single dot in the progress bar
		// so returning customers (who skip OTP via verified-client cache)
		// still see a consistent count. The actual step at the time of
		// render gets the `is-current` styling regardless.
		// 'success' is the confirmation card and stays out of the dot count.
		var phoneish = ( 'phone' === this.state.step || 'otp' === this.state.step ) ? 'phone' : 'phone';
		// Direct: phone → details → cal (3 dots).
		// Project: phone → details → pick-days → review-days (4 dots).
		return this.isProject
			? [ phoneish, 'details', 'pick-days', 'review-days' ]
			: [ phoneish, 'details', 'cal' ];
	};

	// Override progress active-index lookup so 'otp' counts as 'phone'.
	HandikBookingForm.prototype.progressActiveStepName = function () {
		return 'otp' === this.state.step ? 'phone' : this.state.step;
	};

	HandikBookingForm.prototype.progressMarkup = function () {
		if ( 'success' === this.state.step ) {
			return '';
		}
		var steps = this.applicableSteps();
		var activeIndex = Math.max( 0, steps.indexOf( this.progressActiveStepName() ) );
		// Main app uses `grid-template-columns: repeat(6, 1fr)` to fit 6 steps.
		// Our flows are shorter (4 or 5 steps), so override the column count
		// inline so the dots fill the centered .handik-global-progress band
		// evenly instead of left-aligning with empty cells on the right.
		var style = 'grid-template-columns: repeat(' + steps.length + ', minmax(0, 1fr));';
		var html = '<div class="handik-global-progress"><ol class="handik-progress-dots" style="' + style + '" aria-label="' + escapeAttr( this.t( 'progressLabel' ) ) + '">';
		steps.forEach( function ( step, idx ) {
			var classes = '';
			if ( idx <= activeIndex ) { classes += ' is-done'; }
			if ( idx === activeIndex ) { classes += ' is-current'; }
			html += '<li class="' + classes.trim() + '"></li>';
		} );
		html += '</ol></div>';
		return html;
	};

	/**
	 * Confirm modal shown when the customer clicks "Start a new booking" in
	 * the Stuck disclaimer. The link in earlier builds reset state in one
	 * click, which threw away a half-completed Project Work Days selection
	 * on a misclick. Modal mirrors the main form's restartModalMarkup
	 * (booking-app.js:2684-2693) — same shape, same copy.
	 */
	HandikBookingForm.prototype.restartModalMarkup = function () {
		if ( ! this.state.restartConfirmVisible ) {
			return '';
		}
		return '<div class="handik-modal-backdrop" role="presentation">' +
				'<section class="handik-modal" role="dialog" aria-modal="true" aria-label="' + escapeAttr( this.t( 'restartConfirmTitle' ) ) + '">' +
					'<h3>' + escapeHtml( this.t( 'restartConfirmTitle' ) ) + '</h3>' +
					'<p>' + escapeHtml( this.t( 'restartConfirmBody' ) ) + '</p>' +
					'<div class="handik-modal__actions">' +
						'<button type="button" class="handik-btn is-secondary" data-action="restart-cancel">' +
							'<span class="handik-btn__label">' + escapeHtml( this.t( 'restartConfirmCancel' ) ) + '</span>' +
						'</button>' +
						'<button type="button" class="handik-btn is-primary" data-action="restart-confirm">' +
							'<span class="handik-btn__label">' + escapeHtml( this.t( 'restartConfirmCta' ) ) + '</span>' +
						'</button>' +
					'</div>' +
				'</section>' +
			'</div>';
	};

	HandikBookingForm.prototype.disclaimerMarkup = function () {
		// Success-step variant: "All set." + "Book another visit" link. On
		// the confirmation card a "Stuck? Start a new booking" prompt reads
		// like an error nag — replace it with a celebratory note that
		// also lets the customer start a fresh booking if they want to.
		// Mirrors the main form's appFooterDisclaimer success branch.
		if ( 'success' === this.state.step ) {
			return '<aside class="handik-app-disclaimer is-success">' +
					'<p>' + escapeHtml( this.t( 'allSet' ) ) + '</p>' +
					'<p><a href="#" data-action="restart" class="handik-text-link">' + escapeHtml( this.t( 'bookAnother' ) ) + '</a></p>' +
				'</aside>';
		}

		// Default in-flight variant: "Stuck? Start a new booking · Open the
		// booking page directly".
		var directUrl = String( this.state.calBookingUrl || '' );
		var restartLink = '<a href="#" data-action="restart" class="handik-text-link">' + escapeHtml( this.t( 'restartCta' ) ) + '</a>';
		var directLink = directUrl
			? '<a href="' + escapeAttr( directUrl ) + '" target="_blank" rel="noopener" class="handik-text-link">' + escapeHtml( this.t( 'openDirectCta' ) ) + '</a>'
			: '';
		var links = directLink
			? restartLink + '<span class="handik-app-disclaimer__sep" aria-hidden="true"> · </span>' + directLink
			: restartLink;
		return '<aside class="handik-app-disclaimer"><p>' + escapeHtml( this.t( 'stuckPrefix' ) ) + ' ' + links + '</p></aside>';
	};

	// ===================================================================
	// Field + footer helpers
	// ===================================================================

	/**
	 * Render a `.handik-field` exactly like the main app does: an outer
	 * <label> with a <span> caption + an <input> bound via `data-model`.
	 *
	 * @param {Object} opts
	 * @param {string} opts.model        State path the input is bound to (eg. "contact.phone").
	 * @param {string} opts.label        Caption text.
	 * @param {string} [opts.type]       Input type. Default "text".
	 * @param {string} [opts.value]      Current value.
	 * @param {string} [opts.autocomplete] Autocomplete hint.
	 * @param {string} [opts.inputmode]  inputmode hint.
	 * @param {string} [opts.placeholder]
	 * @param {boolean} [opts.required]
	 * @param {string} [opts.error]      Inline error message (rendered when truthy).
	 * @param {string} [opts.inputId]    Force a specific id on the <input>.
	 * @param {string} [opts.rawAttrs]   Extra attributes injected verbatim.
	 * @param {boolean} [opts.skipName]  When `rawAttrs` already supplies a `name=`.
	 * @param {string} [opts.fieldExtraClass]  Extra class on the wrapping <label>.
	 */
	HandikBookingForm.prototype.fieldMarkup = function ( opts ) {
		opts = opts || {};
		var inputId = opts.inputId || ( 'handik-form-' + opts.model.replace( /[^a-z0-9]/gi, '-' ) );
		var errorId = inputId + '-error';
		var fieldClass = 'handik-field' + ( opts.error ? ' is-invalid' : '' ) + ( opts.fieldExtraClass || '' );

		var inputAttrs = [
			'type="' + escapeAttr( opts.type || 'text' ) + '"',
			'id="' + escapeAttr( inputId ) + '"',
			'data-model="' + escapeAttr( opts.model ) + '"',
			'value="' + escapeAttr( String( opts.value == null ? '' : opts.value ) ) + '"'
		];
		if ( ! opts.skipName ) {
			inputAttrs.push( 'name="' + escapeAttr( opts.model ) + '"' );
		}
		if ( opts.autocomplete && ! opts.rawAttrs ) {
			inputAttrs.push( 'autocomplete="' + escapeAttr( opts.autocomplete ) + '"' );
		}
		if ( opts.inputmode ) { inputAttrs.push( 'inputmode="' + escapeAttr( opts.inputmode ) + '"' ); }
		if ( opts.placeholder ) { inputAttrs.push( 'placeholder="' + escapeAttr( opts.placeholder ) + '"' ); }
		if ( opts.required ) { inputAttrs.push( 'required' ); }
		// Email/URL fields default to no autocaps + no spellcheck (mirrors
		// what the main form does for these types). Without these attrs
		// mobile Safari capitalizes the first letter of an email and
		// underlines it as misspelled.
		if ( ! opts.rawAttrs ) {
			var t = opts.type || 'text';
			if ( 'email' === t || 'tel' === t || 'url' === t ) {
				inputAttrs.push( 'autocapitalize="off"' );
				inputAttrs.push( 'spellcheck="false"' );
			}
		}
		// A11y wiring: when the field has an inline error, point the input at
		// it via aria-describedby so screen readers pair the message with the
		// field, and flip aria-invalid so AT can also surface the error state
		// independently of the visible :is-invalid styling.
		if ( opts.error ) {
			inputAttrs.push( 'aria-invalid="true"' );
			inputAttrs.push( 'aria-describedby="' + escapeAttr( errorId ) + '"' );
		}
		if ( opts.rawAttrs ) { inputAttrs.push( opts.rawAttrs ); }

		return '<label class="' + fieldClass + '" for="' + escapeAttr( inputId ) + '">' +
				'<span>' + escapeHtml( opts.label ) + '</span>' +
				'<input ' + inputAttrs.join( ' ' ) + '>' +
				( opts.error ? '<span class="handik-field__error" id="' + escapeAttr( errorId ) + '" role="alert">' + escapeHtml( opts.error ) + '</span>' : '' ) +
			'</label>';
	};

	HandikBookingForm.prototype.footerActionsMarkup = function ( settings ) {
		settings = settings || {};
		var backLabel     = escapeHtml( settings.backLabel || this.t( 'backLabel' ) );
		var continueLabel = escapeHtml( settings.continueLabel || this.t( 'continueLabel' ) );

		var backIcon  = '<span class="handik-btn__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M19 11H7.83l4.88-4.88L11.29 4.7 4 12l7.29 7.3 1.42-1.42L7.83 13H19v-2z"/></svg></span>';
		var backInner = backIcon + '<span class="handik-btn__label">' + backLabel + '</span>';
		var backClass = 'handik-btn is-secondary is-back';
		var continueClass = 'handik-btn ' + ( settings.continueMuted ? 'is-pending' : 'is-primary' ) + ' is-continue';

		var backButton = settings.hideBack
			? ''
			: '<button type="button" data-action="' + escapeAttr( settings.backAction || 'back' ) + '" class="' + backClass + '">' + backInner + '</button>';
		var continueButton = settings.hideContinue
			? ''
			: '<div class="handik-footer-actions__continue">' +
				'<button type="button" data-action="' + escapeAttr( settings.continueAction || 'continue' ) + '" class="' + continueClass + '" aria-disabled="' + ( settings.continueMuted ? 'true' : 'false' ) + '">' +
					'<span class="handik-btn__label">' + continueLabel + '</span>' +
				'</button>' +
			'</div>';

		var actions = ( backButton || continueButton )
			? '<div class="handik-footer-actions is-docked' + ( settings.hideBack || settings.hideContinue ? ' is-single' : '' ) + '">' + backButton + continueButton + '</div>'
			: '';
		return '<div class="handik-footer-wrap">' + actions + '</div>';
	};

	// ===================================================================
	// Event binding
	// ===================================================================

	HandikBookingForm.prototype.bindEvents = function () {
		var self = this;

		this.shell.querySelectorAll( '[data-model]' ).forEach( function ( input ) {
			input.addEventListener( 'input', function () { self.onInput( input ); } );
			input.addEventListener( 'blur',  function () { self.onBlur( input ); } );
		} );

		var savedSelect = this.shell.querySelector( '#handik-form-saved-address' );
		if ( savedSelect ) {
			savedSelect.addEventListener( 'change', function () {
				self.applySavedAddressById( savedSelect.value );
			} );
		}

		this.shell.querySelectorAll( '[data-action]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( ev ) {
				ev.preventDefault();
				self.onAction( btn.getAttribute( 'data-action' ), btn );
			} );
		} );

		// Notification dismiss delegation (one listener per notifications root).
		var notif = this.notificationsRoot;
		if ( notif && ! notif.dataset.handikBound ) {
			notif.addEventListener( 'click', function ( ev ) {
				var dismiss = ev.target.closest && ev.target.closest( '[data-notification-dismiss]' );
				if ( dismiss ) {
					self.dismissNotification( dismiss.getAttribute( 'data-notification-dismiss' ) );
				}
			} );
			notif.dataset.handikBound = '1';
		}
	};

	HandikBookingForm.prototype.onInput = function ( input ) {
		var model = input.getAttribute( 'data-model' );
		if ( ! model ) { return; }
		var value = input.value;
		// Sprint 5: phone input is now SOFT — we don't rewrite the value
		// during typing. Earlier builds reformatted on every keystroke
		// (with a digits-before-caret restore) which produced visible "1 1
		// 1" artifacts when paste / autofill rewrote the field with E.164.
		// The owner specifically asked for a hands-off input experience.
		// We still validate on Continue and run formatPhoneAsYouType on
		// blur for display normalization.
		if ( 'address.address_full' === model ) {
			// Only invalidate Places verification if the customer ACTUALLY
			// retyped (vs the browser autofilling/prefilling the field).
			// `__handikUserTyped` is attached by mountAddressAutocomplete
			// after the Places binding completes.
			var typed = ( typeof input.__handikUserTyped === 'function' )
				? !! input.__handikUserTyped()
				: true;
			if ( typed ) {
				this.state.address.is_valid  = false;
				this.state.address.address_id = 0;
			}
		}
		this.setFieldValue( model, value );
		this.scheduleDraftSave();

		// Hotfix 2.1.13.1: auto-advance the OTP step as soon as the customer
		// has typed a full 6-digit code. The Verify button has been removed
		// from the markup in this build — typing the last digit (or pasting
		// the SMS autofill suggestion) verifies immediately. Guarded against
		// re-entry while the verify request is in flight.
		if ( 'otpCode' === model && 'otp' === this.state.step ) {
			var digits = String( value || '' ).replace( /\D/g, '' );
			if ( digits.length >= 6 && ! this.state.busy && ! this._otpVerifyInFlight ) {
				this.state.otpCode = digits.slice( 0, 6 );
				input.value = this.state.otpCode;
				this.verifyPhoneOtp();
			}
		}
	};

	HandikBookingForm.prototype.onBlur = function ( input ) {
		var model = input.getAttribute( 'data-model' );
		if ( ! model ) { return; }
		var key = model.split( '.' ).pop();
		this.state.touched[ key ] = true;
		// Soft phone format on blur — only when the value has 10 digits and
		// looks legitimate. Never rewrites partial input (avoids the "11 1
		// 234" artifact owners reported with the old per-keystroke
		// formatter). This is purely display normalization; the API value
		// goes through phoneApiValue() which strips formatting anyway.
		if ( 'contact.phone' === model && validatePhone( this.state.contact.phone ) ) {
			var formatted = formatPhoneAsYouType( this.state.contact.phone );
			if ( formatted && formatted !== this.state.contact.phone ) {
				this.state.contact.phone = formatted;
				input.value = formatted;
			}
		}
		// Re-render on any blur within the current step so an error span
		// that's no longer applicable (the customer fixed the email and
		// tabbed to phone) actually disappears.
		if ( 'phone' === this.state.step
			|| 'otp' === this.state.step
			|| 'details' === this.state.step ) {
			this.render();
		}
	};

	/**
	 * Silent (non-advancing) variant of lookupContactAndAdvance. Hits
	 * /contacts/lookup, prefills name + email if blank, stashes saved
	 * addresses, and (when matched) shows the "Welcome back" toast — but
	 * never changes step. Called from phone-blur so a returning customer
	 * sees recognition before they click Continue.
	 */
	HandikBookingForm.prototype.lookupContactSilent = function () {
		var self = this;
		var phoneApi = phoneApiValue( this.state.contact.phone );
		if ( ! phoneApi ) { return; }
		if ( phoneApi === this.lookupLastPhone || phoneApi === this.lookupInFlightPhone ) {
			return;
		}
		this.lookupInFlightPhone = phoneApi;
		this.api( 'POST', 'contacts/lookup', { phone: phoneApi } )
			.then( function ( res ) {
				self.lookupLastPhone     = phoneApi;
				self.lookupInFlightPhone = '';
				if ( res && res.profile && res.profile.contact ) {
					var p = res.profile;
					self.state.profileContactId  = parseInt( p.contact.id, 10 ) || 0;
					self.state.isReturningClient = true;
					if ( ! String( self.state.contact.full_name || '' ).trim() && p.contact.full_name ) {
						self.state.contact.full_name = String( p.contact.full_name );
					}
					if ( ! String( self.state.contact.email || '' ).trim() && p.contact.email ) {
						self.state.contact.email = String( p.contact.email );
					}
					self.state.savedAddresses = Array.isArray( p.addresses ) ? p.addresses : [];
					self.render();
					if ( self.state.savedAddresses.length ) {
						self.toast( 'info', self.t( 'welcomeBack' ) );
					}
				}
			} )
			.catch( function () {
				// Network failures are silent on this path — the customer can
				// still proceed and the lookup will run again on Continue.
				self.lookupInFlightPhone = '';
			} );
	};

	HandikBookingForm.prototype.setFieldValue = function ( path, value ) {
		var parts = path.split( '.' );
		// Single-key models live directly on `state` (otpCode lived here and was
		// silently dropped when the helper only knew about 2-part paths — the
		// typed OTP digits were never persisted, so a blur re-render flushed
		// the field back to its initial empty value).
		if ( 1 === parts.length ) {
			this.state[ parts[0] ] = value;
			return;
		}
		if ( 2 === parts.length && this.state[ parts[0] ] ) {
			this.state[ parts[0] ][ parts[1] ] = value;
		}
	};

	HandikBookingForm.prototype.onAction = function ( action, btn ) {
		// "Disabled" continue buttons still emit click in some browsers — guard.
		if ( btn && 'true' === btn.getAttribute( 'aria-disabled' ) ) {
			return;
		}
		switch ( action ) {
			// Phone step: validate then send OTP via Twilio.
			case 'phone-next':
				this.state.touched.phone = true;
				if ( ! validatePhone( this.state.contact.phone ) ) {
					this.render();
					this.toast( 'warning', this.t( 'errorPhone' ) );
					return;
				}
				this.startPhoneOtp();
				break;
			// OTP step actions.
			case 'otp-verify':
				if ( this.state.otpCode.length < 4 ) { return; }
				this.verifyPhoneOtp();
				break;
			case 'otp-resend':
				if ( Date.now() < this.state.otpResendDisabledUntil ) { return; }
				this.startPhoneOtp( /* isResend */ true );
				break;
			case 'otp-back':
				this.state.otpCode = '';
				this.state.otpError = '';
				this.go( 'phone' );
				break;
			// Combined details step (branches on isReturningClient).
			case 'details-back':
				// New-client path: nowhere safe to "back" except the OTP step.
				// Returning customers can also go back to phone if they typed
				// the wrong number.
				this.go( 'otp' );
				break;
			case 'details-next':
				this.state.touched.address_full = true;
				if ( '' === String( this.state.address.address_full || '' ).trim() ) {
					this.render();
					return;
				}
				if ( this.config.googleMapsApiKey && ! this.state.address.is_valid ) {
					this.render();
					this.toast( 'warning', this.t( 'errorAddressInvalid' ) );
					return;
				}
				// New-client path also requires name; email stays optional.
				if ( ! this.state.isReturningClient ) {
					this.state.touched.full_name = true;
					if ( ! validateFullName( this.state.contact.full_name ) ) {
						this.render();
						this.toast( 'warning', this.t( 'errorRequired' ) );
						return;
					}
					if ( '' !== this.state.contact.email && ! validateEmail( this.state.contact.email ) ) {
						this.render();
						this.toast( 'warning', this.t( 'errorEmail' ) );
						return;
					}
				}
				if ( this.isProject ) {
					this.openProject();
				} else {
					this.submitDirect();
				}
				break;
			// Legacy aliases (Sprint 5 rename safety).
			case 'contact-next':
				this.go( 'phone' );
				break;
			case 'address-back':
				this.go( 'details' );
				break;
			case 'address-next':
				this.onAction( 'details-next', btn );
				break;
			case 'pick-back':
				this.go( 'address' );
				break;
			case 'pick-next':
				if ( this.state.selectedSlots.length !== this.requiredDays ) {
					return;
				}
				this.saveSelection();
				break;
			case 'review-back':
				this.go( 'pick-days' );
				break;
			case 'review-confirm':
				this.confirmSchedule();
				break;
			case 'toggle-slot':
				this.toggleSlot( btn.getAttribute( 'data-slot-start' ), btn.getAttribute( 'data-slot-end' ) );
				break;
			case 'restart':
				// Don't nuke state on first click — show a confirm modal so a
				// misclick on the disclaimer doesn't wipe a partially-completed
				// project selection.
				this.state.restartConfirmVisible = true;
				this.render();
				break;
			case 'restart-cancel':
				this.state.restartConfirmVisible = false;
				this.render();
				break;
			case 'restart-confirm':
				this.state.restartConfirmVisible = false;
				this.restart();
				break;
		}
	};

	HandikBookingForm.prototype.go = function ( step ) {
		var previous = this.state.step;
		this.state.step = step;
		// Push a new history entry only on real step transitions, and never
		// when we're responding to a popstate (would loop). The replace path
		// covers the initial mount; subsequent forward moves push so the
		// browser back button can rewind us one step at a time.
		if ( previous !== step && ! this._navigatingFromHistory ) {
			this.pushHistoryState( step );
		}
		this.render();
		// On long pages (the form is often inside a tall hero / header) the
		// next step renders below the fold and the customer scrolls back up
		// manually after every Continue. Scroll the new screen into view —
		// same 80px header offset and rAF deferral the main app uses.
		if ( previous !== step ) {
			this.scrollStepIntoView();
			// Persist progress so a refresh resumes here. Success cleans
			// up explicitly in confirmSchedule / captureDirectBooking.
			if ( 'success' === step ) {
				this.clearDraftStorage();
			} else {
				this.saveDraftToStorage();
			}
		}
	};

	HandikBookingForm.prototype.scrollStepIntoView = function () {
		var self = this;
		window.requestAnimationFrame( function () {
			var target = self.shell.querySelector( '.handik-booking-app__screen-header' )
				|| self.shell
				|| self.root;
			if ( ! target ) { return; }
			var rect = target.getBoundingClientRect();
			var absoluteTop = rect.top + window.pageYOffset;
			var top = Math.max( 0, absoluteTop - 80 );
			try {
				window.scrollTo( { top: top, behavior: 'smooth' } );
			} catch ( e ) {
				// Older browsers don't accept the {behavior} options object.
				window.scrollTo( 0, top );
			}
		} );
	};

	/**
	 * Reset to a fresh contact step. Mirrors the main app's "Start a new
	 * booking" link in the Stuck disclaimer.
	 */
	HandikBookingForm.prototype.restart = function () {
		// Wipe any persisted draft AND the verified-client token — restart
		// means "throw it away," and that includes the device's identity
		// trust so the next customer (or returning self) re-verifies.
		this.clearDraftStorage();
		clearVerifiedClient();
		this.state.step             = 'phone';
		this.state.otpCode          = '';
		this.state.otpError         = '';
		this.state.otpResendDisabledUntil = 0;
		this.state.verifiedToken    = '';
		this.state.verifiedPhone    = '';
		this.state.busy             = false;
		this.state.contact          = { full_name: '', phone: '', email: '' };
		this.state.address          = {
			address_id: 0, address_full: '', address_unit: '', address_line_1: '',
			city: '', state: '', zip_code: '', is_valid: false
		};
		this.state.touched          = {};
		this.state.directRequestId    = 0;
		this.state.directCaptureToken = '';
		this.state._captureSent       = false;
		this.state.calBookingUrl      = '';
		this.state.scheduleId       = 0;
		this.state.publicToken      = '';
		this.state.slots            = [];
		this.state.slotsLoaded      = false;
		this.state.selectedSlots    = [];
		this.state.confirmedDays    = [];
		this.state.savedAddresses   = [];
		this.state.isReturningClient = false;
		this.state.profileContactId  = 0;
		this.lookupLastPhone        = '';
		this.lookupInFlightPhone    = '';
		this.render();
	};

	// ===================================================================
	// Validation
	// ===================================================================

	HandikBookingForm.prototype.contactValid = function () {
		var c = this.state.contact;
		if ( ! validateFullName( c.full_name ) ) { return false; }
		if ( ! validatePhone( c.phone ) ) { return false; }
		if ( '' !== c.email && ! validateEmail( c.email ) ) { return false; }
		return true;
	};

	HandikBookingForm.prototype.t = function ( key ) {
		var v = this.i18n[ key ];
		return v == null ? '' : String( v );
	};

	// ===================================================================
	// Saved addresses (returning client)
	// ===================================================================

	/**
	 * Apply a saved-address selection by its CRM id. Pulls the matching row
	 * from `state.savedAddresses`, fills the form, and re-renders so the
	 * Continue button unblocks.
	 */
	HandikBookingForm.prototype.applySavedAddressById = function ( id ) {
		if ( '' === String( id ) ) {
			// User picked the placeholder — clear the address so they can
			// type a fresh one.
			this.state.address = {
				address_id: 0, address_full: '', address_unit: '', address_line_1: '',
				city: '', state: '', zip_code: '', is_valid: false
			};
			this.render();
			return;
		}
		var addr = null;
		for ( var i = 0; i < this.state.savedAddresses.length; i++ ) {
			if ( String( this.state.savedAddresses[ i ].id ) === String( id ) ) {
				addr = this.state.savedAddresses[ i ];
				break;
			}
		}
		if ( ! addr ) { return; }
		this.state.address = {
			address_id:    parseInt( addr.id, 10 ) || 0,
			address_full:  String( addr.address_full || addr.address_line_1 || '' ),
			address_unit:  String( addr.address_unit || '' ),
			address_line_1: String( addr.address_line_1 || '' ),
			city:          String( addr.city || '' ),
			state:         String( addr.state || '' ),
			zip_code:      String( addr.zip_code || '' ),
			is_valid:      true
		};
		this.render();
		var unit = this.shell.querySelector( '#handik-form-address-unit' );
		if ( unit ) {
			try { unit.focus( { preventScroll: true } ); } catch ( e ) { /* ignore */ }
		}
	};

	// ===================================================================
	// Network
	// ===================================================================

	HandikBookingForm.prototype.api = function ( method, path, body ) {
		var self = this;
		var url = this.config.restBase + String( path ).replace( /^\//, '' );
		var opts = {
			method: method,
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': this.config.restNonce || ''
			}
		};
		if ( body && 'GET' !== method ) {
			opts.body = JSON.stringify( body );
		}
		return window.fetch( url, opts ).then( function ( res ) {
			return res.json().then( function ( json ) {
				if ( ! res.ok ) {
					var err = new Error( ( json && json.message ) || self.t( 'genericError' ) );
					err.payload = json;
					err.status = res.status;
					throw err;
				}
				return json;
			} );
		} );
	};

	// =====================================================================
	// Phone-first OTP flow (Sprint 5)
	// =====================================================================

	/**
	 * Send a Twilio Verify OTP to the customer's phone. On success, transition
	 * to the OTP screen. On a resend, keep the user on the OTP screen and
	 * arm the local 30s lockout.
	 */
	HandikBookingForm.prototype.startPhoneOtp = function ( isResend ) {
		var self = this;
		var phoneApi = phoneApiValue( this.state.contact.phone );
		if ( ! phoneApi ) {
			this.toast( 'warning', this.t( 'errorPhone' ) );
			return;
		}
		this.busy( true );
		this.api( 'POST', 'phone-verify/start', { phone: phoneApi } )
			.then( function ( res ) {
				self.state.otpError = '';
				self.state.otpResendDisabledUntil = Date.now() + 30 * 1000;
				if ( ! isResend ) {
					self.go( 'otp' );
				}
				self.toast( 'info', ( res && res.message ) || self.t( 'otpSentToast' ) );
			} )
			.catch( function ( err ) {
				self.toast( 'error', ( err && err.message ) || self.t( 'genericError' ) );
			} )
			.then( function () { self.busy( false ); } );
	};

	/**
	 * Submit the OTP. On approved, branch the SPA based on whether the phone
	 * matched a CRM contact. New clients land on a name+email+address screen;
	 * returning clients land on a saved-address+address screen with their
	 * profile already prefilled.
	 *
	 * Stashes the verified-client token in localStorage so a refresh within
	 * the TTL skips the OTP step entirely.
	 */
	HandikBookingForm.prototype.verifyPhoneOtp = function () {
		var self = this;
		// Hotfix 2.1.13.1: re-entry guard — auto-advance fires this on the
		// keystroke that completes the 6th digit, but iOS-autofill paths can
		// also fire a duplicate input event right after. Without this guard
		// we'd POST /phone-verify/check twice and Twilio invalidates the
		// verification on the first hit, so the second returns 404.
		if ( this._otpVerifyInFlight ) { return; }
		this._otpVerifyInFlight = true;
		var phoneApi = phoneApiValue( this.state.contact.phone );
		this.busy( true );
		this.api( 'POST', 'phone-verify/check', { phone: phoneApi, code: this.state.otpCode } )
			.then( function ( res ) {
				self.state.otpError = '';
				self.state.verifiedToken = String( res.verified_token || '' );
				self.state.verifiedPhone = String( res.verified_phone || phoneApi );
				self.state.isReturningClient = ! res.is_new_client;
				self.state.profileContactId = parseInt( res.contact_id, 10 ) || 0;

				// Returning: prefill name/email/saved-addresses from profile.
				if ( res.profile && res.profile.contact ) {
					var p = res.profile;
					if ( p.contact.full_name && '' === self.state.contact.full_name ) {
						self.state.contact.full_name = String( p.contact.full_name );
					}
					if ( p.contact.email && '' === self.state.contact.email ) {
						self.state.contact.email = String( p.contact.email );
					}
					self.state.savedAddresses = Array.isArray( p.addresses ) ? p.addresses : [];
				} else {
					self.state.savedAddresses = [];
				}

				saveVerifiedClient( {
					token: self.state.verifiedToken,
					phone: self.state.verifiedPhone,
					savedAt: Date.now()
				} );

				self.go( 'details' );
				if ( self.state.isReturningClient ) {
					self.toast( 'info', self.t( 'welcomeBack' ) );
				}
			} )
			.catch( function ( err ) {
				self.state.otpError = ( err && err.message ) || self.t( 'otpInvalid' );
				// Wipe the buffer so the customer can retype without having to
				// hit "Use a different number" / clear by hand. The auto-advance
				// will re-fire when they type a fresh 6 digits.
				self.state.otpCode = '';
				self.render();
			} )
			.then( function () {
				self._otpVerifyInFlight = false;
				self.busy( false );
			} );
	};

	/**
	 * On boot, if a verified-client token is stashed in localStorage and not
	 * expired, ask the server to revalidate (HMAC + TTL) and rehydrate the
	 * profile. On success, skip the phone+otp steps and start at `details`.
	 */
	HandikBookingForm.prototype.tryRestoreVerifiedClient = function () {
		var self = this;
		var stored = readVerifiedClient();
		if ( ! stored || ! stored.token ) { return Promise.resolve( false ); }
		return this.api( 'POST', 'phone-verify/restore', { verified_token: stored.token } )
			.then( function ( res ) {
				self.state.verifiedToken = stored.token;
				self.state.verifiedPhone = String( res.verified_phone || stored.phone || '' );
				self.state.contact.phone = self.state.verifiedPhone
					? formatPhoneAsYouType( self.state.verifiedPhone )
					: self.state.contact.phone;
				self.state.isReturningClient = ! res.is_new_client;
				self.state.profileContactId = parseInt( res.contact_id, 10 ) || 0;
				if ( res.profile && res.profile.contact ) {
					var p = res.profile;
					self.state.contact.full_name = String( p.contact.full_name || '' );
					self.state.contact.email     = String( p.contact.email || '' );
					self.state.savedAddresses    = Array.isArray( p.addresses ) ? p.addresses : [];
				}
				self.state.step = 'details';
				return true;
			} )
			.catch( function () {
				// Token expired or server invalidated — wipe local cache.
				clearVerifiedClient();
				return false;
			} );
	};

	HandikBookingForm.prototype.lookupContactAndAdvance = function () {
		var self = this;
		var phoneApi = phoneApiValue( this.state.contact.phone );
		if ( ! phoneApi ) {
			this.go( 'address' );
			return;
		}
		if ( phoneApi === this.lookupLastPhone || phoneApi === this.lookupInFlightPhone ) {
			this.go( 'address' );
			return;
		}
		this.lookupInFlightPhone = phoneApi;
		this.busy( true );

		this.api( 'POST', 'contacts/lookup', { phone: phoneApi } )
			.then( function ( res ) {
				self.lookupLastPhone     = phoneApi;
				self.lookupInFlightPhone = '';
				if ( res && res.profile && res.profile.contact ) {
					var p = res.profile;
					self.state.profileContactId   = parseInt( p.contact.id, 10 ) || 0;
					self.state.isReturningClient  = true;
					if ( ! String( self.state.contact.full_name || '' ).trim() && p.contact.full_name ) {
						self.state.contact.full_name = String( p.contact.full_name );
					}
					if ( ! String( self.state.contact.email || '' ).trim() && p.contact.email ) {
						self.state.contact.email = String( p.contact.email );
					}
					self.state.savedAddresses = Array.isArray( p.addresses ) ? p.addresses : [];
					if ( self.state.savedAddresses.length ) {
						self.toast( 'info', self.t( 'welcomeBack' ) );
					}
				} else {
					self.state.isReturningClient = false;
					self.state.savedAddresses    = [];
				}
				self.go( 'address' );
			} )
			.catch( function () {
				// Lookup failures are non-blocking. Treat as a brand-new client.
				self.lookupInFlightPhone = '';
				self.go( 'address' );
			} )
			.then( function () { self.busy( false ); } );
	};

	HandikBookingForm.prototype.submitDirect = function () {
		var self = this;
		this.busy( true );
		var payload = this.contactPayload();
		this.api( 'POST', 'forms/direct/submit?preset_slug=' + encodeURIComponent( this.preset.preset_slug ), payload )
			.then( function ( res ) {
				self.state.directRequestId   = parseInt( res.request_id, 10 ) || 0;
				self.state.calBookingUrl     = String( res.cal_booking_url || '' );
				// Server-issued per-row token. Required on the matching
				// /capture POST so the booking record can't be mutated by
				// a third party iterating the auto-increment id.
				self.state.directCaptureToken = String( res.capture_token || '' );
				self.go( 'cal' );
			} )
			.catch( function ( err ) { self.toast( 'error', err.message ); } )
			.then( function () { self.busy( false ); } );
	};

	HandikBookingForm.prototype.openProject = function () {
		var self = this;
		this.busy( true );
		var payload = this.contactPayload();
		this.api( 'POST', 'forms/project/open?preset_slug=' + encodeURIComponent( this.preset.preset_slug ), payload )
			.then( function ( res ) {
				self.state.scheduleId  = parseInt( res.schedule_id, 10 ) || 0;
				self.state.publicToken = String( res.public_token || '' );
				self.state.slotsLoaded = false;
				self.state.slots       = [];
				self.go( 'pick-days' );
				return self.fetchSlots();
			} )
			.catch( function ( err ) { self.toast( 'error', err.message ); } )
			.then( function () { self.busy( false ); } );
	};

	HandikBookingForm.prototype.fetchSlots = function () {
		var self = this;
		var path = 'forms/project/' + encodeURIComponent( this.state.scheduleId )
			+ '/slots?token=' + encodeURIComponent( this.state.publicToken );
		return this.api( 'GET', path ).then( function ( res ) {
			self.state.slots       = Array.isArray( res.slots ) ? res.slots : [];
			self.state.slotsLoaded = true;
			self.render();
		} );
	};

	HandikBookingForm.prototype.toggleSlot = function ( startIso, endIso ) {
		var existing = -1;
		for ( var i = 0; i < this.state.selectedSlots.length; i++ ) {
			if ( this.state.selectedSlots[ i ].start === startIso ) { existing = i; break; }
		}
		if ( existing >= 0 ) {
			this.state.selectedSlots.splice( existing, 1 );
		} else {
			if ( this.state.selectedSlots.length >= this.requiredDays ) { return; }
			this.state.selectedSlots.push( { start: startIso, end: endIso || '' } );
		}
		this.render();
	};

	HandikBookingForm.prototype.saveSelection = function () {
		var self = this;
		this.busy( true );
		var path = 'forms/project/' + encodeURIComponent( this.state.scheduleId )
			+ '/select?token=' + encodeURIComponent( this.state.publicToken );
		this.api( 'POST', path, { selected_slots: this.state.selectedSlots } )
			.then( function () { self.go( 'review-days' ); } )
			.catch( function ( err ) { self.toast( 'error', err.message ); } )
			.then( function () { self.busy( false ); } );
	};

	HandikBookingForm.prototype.confirmSchedule = function () {
		var self = this;
		this.busy( true );
		var path = 'forms/project/' + encodeURIComponent( this.state.scheduleId )
			+ '/confirm?token=' + encodeURIComponent( this.state.publicToken );
		this.api( 'POST', path, {} )
			.then( function () {
				self.state.confirmedDays = self.state.selectedSlots.slice();
				self.go( 'success' );
			} )
			.catch( function ( err ) {
				if ( err.payload && Array.isArray( err.payload.missing ) && err.payload.missing.length ) {
					var missingKeys = {};
					err.payload.missing.forEach( function ( m ) { missingKeys[ m.start_iso ] = true; } );
					self.state.selectedSlots = self.state.selectedSlots.filter( function ( s ) {
						return ! missingKeys[ s.start ];
					} );
					self.toast( 'warning', self.t( 'replacementNeeded' ) );
					return self.fetchSlots().then( function () { self.go( 'pick-days' ); } );
				}
				self.toast( 'error', err.message );
			} )
			.then( function () { self.busy( false ); } );
	};

	HandikBookingForm.prototype.contactPayload = function () {
		var c = this.state.contact;
		var a = this.state.address;
		return {
			preset_slug:  this.preset.preset_slug,
			full_name:    c.full_name,
			phone:        c.phone,
			email:        c.email,
			address_full: a.address_full,
			address_unit: a.address_unit,
			source_url:   this.config.sourceUrl || ''
		};
	};

	/**
	 * Toggle the busy flag and re-render so the Continue button reflects
	 * the new state. Earlier builds tried to mutate aria-busy / aria-disabled
	 * directly on existing button DOM nodes, but `aria-disabled` lingered
	 * after the request finished — leaving the Confirm button stuck on
	 * follow-up screens (Project Work Days → Review → "Confirm selected
	 * days" was un-clickable). Re-rendering keeps render() as the single
	 * source of truth: the markup helpers compute `continueMuted` from
	 * state.busy + validation, so the button is always in sync.
	 *
	 * busy() is only called from network-action paths (no input is in focus)
	 * so the re-render doesn't disrupt typing.
	 */
	HandikBookingForm.prototype.busy = function ( on ) {
		this.state.busy = !! on;
		this.render();
	};

	// ===================================================================
	// Cal embed (matches main app's window.Cal API)
	// ===================================================================

	/**
	 * Bootstrap the Cal.com embed loader using the same pattern the main app
	 * uses — which is the canonical pattern Cal.com publishes in its embed
	 * docs.
	 *
	 * Why this is more involved than a plain `<script src="…/embed.js">`:
	 * embed.js, once it runs, expects `window.Cal` to already be a queue
	 * function so it can drain pending calls (made before the script loaded)
	 * and replace itself with the real implementation. If `window.Cal` is
	 * undefined when embed.js executes, you get the "Cal is not defined.
	 * This shouldn't happen" error and a white iframe.
	 *
	 * The fix: install a queue stub that, on first invocation, appends the
	 * embed script and tracks namespace-scoped queues. Then poll for the
	 * stub to be present before resolving the load promise.
	 */
	HandikBookingForm.prototype.loadCalScript = function ( origin ) {
		if ( window.Cal && 'function' === typeof window.Cal ) {
			return Promise.resolve( window.Cal );
		}
		if ( this.calEmbedPromise ) {
			return this.calEmbedPromise;
		}

		this.calEmbedPromise = new Promise( function ( resolve, reject ) {
			var embedOrigin = origin || 'https://app.cal.com';
			var embedUrl    = embedOrigin.replace( /\/+$/, '' ) + '/embed/embed.js';

			var bootstrap = function () {
				if ( window.Cal && 'function' === typeof window.Cal ) {
					return;
				}
				window.Cal = window.Cal || function () {
					var cal  = window.Cal;
					var args = arguments;
					var push = function ( api, apiArgs ) {
						api.q = api.q || [];
						api.q.push( apiArgs );
					};

					if ( ! cal.loaded ) {
						cal.ns = cal.ns || {};
						cal.q  = cal.q || [];
						var existing = document.getElementById( CAL_EMBED_SCRIPT_ID );
						if ( ! existing ) {
							var script = document.createElement( 'script' );
							script.id    = CAL_EMBED_SCRIPT_ID;
							script.async = true;
							script.src   = embedUrl;
							script.addEventListener( 'error', function () {
								reject( new Error( 'Cal.com embed failed to load.' ) );
							} );
							document.head.appendChild( script );
						}
						cal.loaded = true;
					}

					if ( 'init' === args[0] ) {
						var namespace = args[1];
						var api = function () { push( api, arguments ); };
						api.q = api.q || [];
						if ( 'string' === typeof namespace ) {
							cal.ns[ namespace ] = cal.ns[ namespace ] || api;
							push( cal.ns[ namespace ], args );
							push( cal, [ 'initNamespace', namespace ] );
						} else {
							push( cal, args );
						}
						return;
					}

					push( cal, args );
				};
			};

			bootstrap();

			// Poll for the queue stub to be present (it should be set by
			// bootstrap() synchronously, but stay defensive in case a third
			// party clobbers window.Cal). Up to 5 seconds (50 × 100ms).
			var attempts = 0;
			var poll = function () {
				attempts += 1;
				if ( window.Cal && 'function' === typeof window.Cal ) {
					resolve( window.Cal );
					return;
				}
				if ( attempts >= 50 ) {
					reject( new Error( 'Cal.com embed API is not available.' ) );
					return;
				}
				window.setTimeout( poll, 100 );
			};
			poll();
		} );

		return this.calEmbedPromise;
	};

	/**
	 * Booking-page query parameters that must NOT be forwarded to the inline
	 * Cal embed. They're meaningful only on the standalone Cal.com page (deep
	 * linking to a specific date/slot via the iframe surface). When forwarded
	 * to embed.js they cause the calendar to deep-link past the picker into
	 * a "no slots available" state — masking the real availability the
	 * customer needs to see.
	 *
	 * Mirrors the same blacklist the main `[handik_booking_app]` form uses
	 * in its parseBookingEmbedConfig at booking-app.js:2149.
	 */
	var CAL_EMBED_DROP_PARAMS = {
		overlayCalendar: true,
		month: true,
		date: true,
		slot: true,
		embed: true,
		embed_origin: true,
		layout: true
	};

	HandikBookingForm.prototype.parseCalEmbedConfig = function () {
		if ( ! this.state.calBookingUrl ) { return null; }
		var bookingUrl;
		try { bookingUrl = new URL( this.state.calBookingUrl ); }
		catch ( e ) { return null; }
		var pathParts = bookingUrl.pathname.split( '/' ).filter( Boolean );
		if ( ! pathParts.length ) { return null; }
		var calLink = pathParts.join( '/' );
		var embedConfig = {};
		bookingUrl.searchParams.forEach( function ( value, key ) {
			if ( CAL_EMBED_DROP_PARAMS[ key ] ) {
				return;
			}
			if ( 'phone' === key ) {
				var v = String( value || '' ).replace( /[\s()-]+/g, '' );
				embedConfig[ key ] = v && '+' !== v.charAt( 0 ) ? '+' + v : v;
				return;
			}
			embedConfig[ key ] = value;
		} );
		return {
			origin: bookingUrl.origin || 'https://cal.com',
			calLink: calLink,
			config: embedConfig
		};
	};

	HandikBookingForm.prototype.mountCalEmbed = function () {
		var self = this;
		var container = this.shell.querySelector( '[data-handik-cal-embed]' );
		if ( ! container ) { return; }
		// Per-node idempotency: if this exact container has already been
		// mounted, skip. A step-change-and-return rebuilds the container so
		// the attribute is fresh, allowing re-mount on a different DOM node.
		if ( '1' === container.getAttribute( 'data-handik-cal-mounted' ) ) {
			return;
		}
		container.setAttribute( 'data-handik-cal-mounted', '1' );

		var parsed = this.parseCalEmbedConfig();
		if ( ! parsed ) {
			container.innerHTML = '<div class="handik-booking-app__alert is-error" role="alert">' + escapeHtml( this.t( 'calNotReady' ) ) + '</div>';
			return;
		}

		// 15s timeout falls back to a single "Open in new tab" CTA when the
		// embed script never loads (ad-block, network failure, CSP, etc).
		var fallbackTimer = window.setTimeout( function () {
			if ( self.state.calBookingUrl ) {
				container.innerHTML = '<div class="handik-booking-app__booking-direct">' +
					'<a class="handik-btn is-primary" href="' + escapeAttr( self.state.calBookingUrl ) + '" target="_blank" rel="noopener noreferrer">' +
					'<span class="handik-btn__label">' + escapeHtml( self.t( 'openInNewTab' ) ) + '</span></a></div>';
			}
		}, CAL_EMBED_TIMEOUT_MS );

		this.loadCalScript( parsed.origin )
			.then( function () {
				container.innerHTML = '<div class="handik-booking-app__booking-frame-wrap" data-handik-cal-frame></div>';

				// Namespace the embed instance per form so multiple forms on
				// the same page (rare but possible) don't fight over the
				// global Cal namespace map.
				var namespace = 'handik_form_' + self.instanceId;
				self.calEmbedNamespace = namespace;
				window.__handikCalNamespaces = window.__handikCalNamespaces || {};

				if ( ! window.__handikCalNamespaces[ namespace ] ) {
					// `init` runs through the queue stub: it appends the
					// embed script (if not already), creates cal.ns[namespace]
					// as its own queue function, and queues the init payload
					// for embed.js to drain when it loads.
					window.Cal( 'init', namespace, { origin: parsed.origin } );
					window.__handikCalNamespaces[ namespace ] = true;
				}
				var ns = ( window.Cal.ns && window.Cal.ns[ namespace ] ) ? window.Cal.ns[ namespace ] : window.Cal;
				ns( 'inline', {
					elementOrSelector: container.querySelector( '[data-handik-cal-frame]' ),
					calLink: parsed.calLink,
					config: parsed.config
				} );
				ns( 'on', {
					action: 'bookingSuccessful',
					callback: function ( e ) {
						self.captureDirectBooking( e && e.detail && e.detail.data ? e.detail.data : ( e && e.detail ? e.detail : {} ) );
					}
				} );
				window.clearTimeout( fallbackTimer );
			} )
			.catch( function ( err ) {
				window.clearTimeout( fallbackTimer );
				container.innerHTML = '<div class="handik-booking-app__alert is-error" role="alert">' +
					escapeHtml( ( err && err.message ) || self.t( 'genericError' ) ) +
				'</div>';
			} );
	};

	HandikBookingForm.prototype.captureDirectBooking = function ( detail ) {
		var self = this;
		if ( ! this.state.directRequestId ) { return; }
		// Local debounce: Cal embed sometimes fires `bookingSuccessful` more
		// than once per booking. Only the first call should attempt to
		// capture — subsequent ones are no-ops on the client too. The
		// server-side guard catches anything we miss.
		if ( this.state._captureSent ) { return; }
		this.state._captureSent = true;
		this.api(
			'POST',
			'forms/direct/' + encodeURIComponent( this.state.directRequestId ) + '/capture',
			{
				booking_payload: detail || {},
				capture_token:   String( this.state.directCaptureToken || '' )
			}
		)
			.then( function () {
				self.state.confirmedDays = [];
				self.go( 'success' );
			} )
			.catch( function ( err ) {
				// Allow retry if the request actually failed.
				self.state._captureSent = false;
				self.toast( 'error', ( err && err.message ) || self.t( 'genericError' ) );
			} );
	};

	// ===================================================================
	// Google Maps Places autocomplete (mirrors main app behavior)
	// ===================================================================

	HandikBookingForm.prototype.loadGoogleMapsPlaces = function () {
		var self = this;
		if ( ! this.config.googleMapsApiKey ) {
			return Promise.resolve( null );
		}
		if ( window.google && window.google.maps && window.google.maps.places ) {
			return Promise.resolve( window.google.maps );
		}
		if ( this.googleMapsPromise ) {
			return this.googleMapsPromise;
		}
		this.googleMapsPromise = new Promise( function ( resolve, reject ) {
			var existing = document.getElementById( GOOGLE_SCRIPT_ID );
			if ( existing ) {
				existing.addEventListener( 'load', function () {
					resolve( window.google && window.google.maps ? window.google.maps : null );
				}, { once: true } );
				existing.addEventListener( 'error', function () {
					reject( new Error( 'Google Maps script failed to load.' ) );
				}, { once: true } );
				return;
			}
			var s = document.createElement( 'script' );
			s.id    = GOOGLE_SCRIPT_ID;
			s.async = true;
			s.defer = true;
			s.src   = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent( self.config.googleMapsApiKey ) + '&libraries=places';
			s.onload  = function () { resolve( window.google && window.google.maps ? window.google.maps : null ); };
			s.onerror = function () { reject( new Error( 'Google Maps script failed to load.' ) ); };
			document.head.appendChild( s );
		} );
		return this.googleMapsPromise;
	};

	HandikBookingForm.prototype.mountAddressAutocomplete = function () {
		var self = this;
		var input = this.shell.querySelector( '#handik-form-address' );
		if ( ! input || ! this.config.googleMapsApiKey ) { return; }
		this.disableNativeAddressAutofill( input );
		if ( '1' === input.getAttribute( 'data-google-mounted' ) ) { return; }

		// Sprint 5: prefill-vs-typed collision. The mobile/desktop browser
		// can fire `change` events with auto-completed strings BEFORE
		// Google Places binds, leaving `state.address.is_valid=false` even
		// for a usable address. Capture the customer's first interaction
		// (focus + first keypress) so we know whether the current input
		// content came from THEM or from an autofill pre-population, and
		// only invalidate the Places verification when they actually
		// retyped after the bind.
		var userTyped = false;
		var markUserTyped = function () { userTyped = true; };
		input.addEventListener( 'keydown', markUserTyped, { once: true } );

		this.loadGoogleMapsPlaces().then( function ( gm ) {
			if ( ! gm || ! gm.places ) { return; }
			self.addressAutocomplete = new gm.places.Autocomplete( input, {
				fields: [ 'address_components', 'formatted_address', 'geometry' ],
				types: [ 'address' ],
				componentRestrictions: self.config.googleMapsCountry ? { country: self.config.googleMapsCountry } : undefined
			} );
			self.addressAutocomplete.addListener( 'place_changed', function () {
				var place = self.addressAutocomplete.getPlace();
				if ( ! place ) { return; }
				var parsed = parseAddressComponents( place );
				self.state.address = Object.assign( {}, self.state.address, parsed, { address_id: 0 } );
				// A Places pick is the customer's authoritative answer —
				// reset the userTyped flag so subsequent re-renders don't
				// invalidate this address until they actually retype.
				userTyped = false;
				self.render();
			} );
			// Expose the typed flag to onInput so it can decide whether an
			// address.address_full edit should clear is_valid.
			input.__handikUserTyped = function () { return userTyped; };
			input.setAttribute( 'data-google-mounted', '1' );
			// Re-apply the autofill suppression — Google can rewrite the
			// `autocomplete` attribute internally when it binds.
			self.disableNativeAddressAutofill( input );
		} ).catch( function ( err ) {
			self.googleMapsPromise = Promise.resolve( null );
			console.error( '[HandikBookingForm] Google Maps autocomplete failed.', err );
		} );
	};

	/**
	 * Suppress Chrome/Safari's native address-fill so the Google Places
	 * dropdown doesn't fight it. Mirrors the main app's identical helper.
	 */
	HandikBookingForm.prototype.disableNativeAddressAutofill = function ( input ) {
		if ( ! input ) { return; }
		input.setAttribute( 'autocomplete', 'new-password' );
		input.setAttribute( 'autocorrect', 'off' );
		input.setAttribute( 'autocapitalize', 'off' );
		input.setAttribute( 'spellcheck', 'false' );
		input.setAttribute( 'data-lpignore', 'true' );
		input.setAttribute( 'data-1p-ignore', 'true' );
		input.setAttribute( 'data-form-type', 'other' );
		input.setAttribute( 'name', 'handik_form_location_query' );
	};

	// ===================================================================
	// Toast notifications (visually identical to main app)
	// ===================================================================

	HandikBookingForm.prototype.toast = function ( type, message, opts ) {
		opts = opts || {};
		var id = 'h-form-n-' + ( ++this.notificationCounter );
		var duration = Math.max( 1200, opts.duration || TOAST_DURATION_MS );
		var item = document.createElement( 'div' );
		item.className = 'handik-toast handik-toast--' + ( type || 'info' );
		item.setAttribute( 'role', 'error' === type ? 'alert' : 'status' );
		item.setAttribute( 'aria-live', 'error' === type ? 'assertive' : 'polite' );
		item.setAttribute( 'data-notification-id', id );
		item.style.setProperty( '--handik-toast-duration', duration + 'ms' );
		item.style.setProperty( '--handik-toast-progress-start', '1' );
		item.innerHTML =
			'<div class="handik-toast__icon" aria-hidden="true">' + toastIcon( type ) + '</div>' +
			'<div class="handik-toast__content">' +
				'<div class="handik-toast__body">' +
					( opts.title ? '<strong>' + escapeHtml( opts.title ) + '</strong>' : '' ) +
					( message ? '<span>' + escapeHtml( message ) + '</span>' : '' ) +
				'</div>' +
			'</div>' +
			'<button type="button" class="handik-toast__close" data-notification-dismiss="' + id + '" aria-label="Dismiss notification">&times;</button>';

		this.notificationsRoot.appendChild( item );
		// Force a synchronous reflow so the initial opacity:0 is committed
		// before we toggle `is-visible` and the entrance transition runs.
		void item.offsetWidth;
		item.classList.add( 'is-visible' );

		var timer = window.setTimeout( function () {
			item.classList.remove( 'is-visible' );
			item.classList.add( 'is-closing' );
			window.setTimeout( function () { if ( item.parentNode ) { item.parentNode.removeChild( item ); } }, 320 );
		}, duration );
		this.notificationTimers.set( id, timer );
	};

	HandikBookingForm.prototype.dismissNotification = function ( id ) {
		var timer = this.notificationTimers.get( id );
		if ( timer ) { window.clearTimeout( timer ); this.notificationTimers.delete( id ); }
		var node = this.notificationsRoot.querySelector( '[data-notification-id="' + id + '"]' );
		if ( ! node ) { return; }
		node.classList.remove( 'is-visible' );
		node.classList.add( 'is-closing' );
		window.setTimeout( function () { if ( node.parentNode ) { node.parentNode.removeChild( node ); } }, 320 );
	};

	// ===================================================================
	// Pure helpers
	// ===================================================================

	// =====================================================================
	// Verified-client cache helpers (Sprint 5)
	// =====================================================================

	var VERIFIED_CLIENT_STORAGE_KEY = 'handik_verified_client_v1';
	var VERIFIED_CLIENT_TTL_MS      = 30 * 24 * 60 * 60 * 1000; // 30 days.

	function readVerifiedClient() {
		if ( ! window.localStorage ) { return null; }
		var raw;
		try { raw = window.localStorage.getItem( VERIFIED_CLIENT_STORAGE_KEY ); }
		catch ( e ) { return null; }
		if ( ! raw ) { return null; }
		try {
			var v = JSON.parse( raw );
			if ( ! v || ! v.token ) { return null; }
			if ( v.savedAt && Date.now() - v.savedAt > VERIFIED_CLIENT_TTL_MS ) {
				clearVerifiedClient();
				return null;
			}
			return v;
		} catch ( e ) {
			clearVerifiedClient();
			return null;
		}
	}

	function saveVerifiedClient( v ) {
		if ( ! window.localStorage ) { return; }
		try {
			window.localStorage.setItem( VERIFIED_CLIENT_STORAGE_KEY, JSON.stringify( v ) );
		} catch ( e ) { /* quota; ignore */ }
	}

	function clearVerifiedClient() {
		if ( ! window.localStorage ) { return; }
		try { window.localStorage.removeItem( VERIFIED_CLIENT_STORAGE_KEY ); }
		catch ( e ) { /* ignore */ }
	}

	function parseConfig( root ) {
		var node = root.querySelector( '[data-handik-booking-form-config]' );
		if ( ! node ) { throw new Error( 'Missing booking form config' ); }
		try { return JSON.parse( node.textContent || '{}' ); }
		catch ( err ) { throw new Error( 'Could not parse booking form config' ); }
	}

	// Mirrors the main app's validator (booking-app.js:834): allow letters
	// (any script via \p{L}), spaces, apostrophe, period (for "Jr."), hyphen.
	function validateFullName( v ) {
		return /^[\p{L}][\p{L}\s'.-]*$/u.test( String( v == null ? '' : v ).trim() );
	}
	function validateEmail( v ) {
		return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( String( v == null ? '' : v ).trim() );
	}
	function phoneDigits( v ) {
		var d = String( v == null ? '' : v ).replace( /\D/g, '' );
		if ( d.length > 10 && '1' === d.charAt( 0 ) ) { d = d.slice( 1 ); }
		return d.slice( 0, 10 );
	}
	function validatePhone( v ) {
		return 10 === phoneDigits( v ).length;
	}
	function phoneApiValue( v ) {
		var d = phoneDigits( v );
		return 10 === d.length ? '+1' + d : '';
	}
	function formatPhoneAsYouType( v ) {
		var d = phoneDigits( v );
		if ( ! d ) { return ''; }
		var parts = [];
		if ( d.length > 0 ) { parts.push( d.slice( 0, Math.min( 3, d.length ) ) ); }
		if ( d.length > 3 ) { parts.push( d.slice( 3, Math.min( 6, d.length ) ) ); }
		if ( d.length > 6 ) { parts.push( d.slice( 6, Math.min( 8, d.length ) ) ); }
		if ( d.length > 8 ) { parts.push( d.slice( 8, Math.min( 10, d.length ) ) ); }
		return '+1 ' + parts.join( ' ' );
	}

	function escapeHtml( s ) {
		return String( s == null ? '' : s )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}
	function escapeAttr( s ) { return escapeHtml( s ); }

	function toastIcon( type ) {
		if ( 'error' === type ) { return '&times;'; }
		if ( 'warning' === type ) { return '&#9888;'; }
		return '&#9432;';
	}

	function parseAddressComponents( place ) {
		var components = Array.isArray( place.address_components ) ? place.address_components : [];
		function value( type, useShort ) {
			var match = components.find( function ( c ) { return Array.isArray( c.types ) && c.types.indexOf( type ) !== -1; } );
			if ( ! match ) { return ''; }
			return useShort ? ( match.short_name || '' ) : ( match.long_name || '' );
		}
		var streetNumber = value( 'street_number', false );
		var route = value( 'route', false );
		var subpremise = value( 'subpremise', false );
		var lineOne = [ streetNumber, route ].filter( Boolean ).join( ' ' ).trim();
		var postalCode = value( 'postal_code', false );
		var state = value( 'administrative_area_level_1', true );
		// is_valid parity with the main app (booking-app.js:2922-2929):
		// require formatted_address + line one + geometry AND a postal code
		// + state. Earlier we accepted Place results missing ZIP/state, then
		// the server payload would arrive at /forms/* without the fields the
		// CRM and Cal location string expect.
		return {
			address_full:   place.formatted_address || '',
			address_line_1: lineOne || place.formatted_address || '',
			address_unit:   subpremise || '',
			city:  value( 'locality', false ) || value( 'postal_town', false ) || value( 'sublocality_level_1', false ),
			state: state,
			zip_code: postalCode,
			is_valid: !! (
				place.formatted_address
				&& lineOne
				&& postalCode
				&& state
				&& place.geometry
				&& place.geometry.location
			)
		};
	}

	function groupSlotsByDay( slots, timezone ) {
		var groups = {};
		var order = [];
		slots.forEach( function ( slot ) {
			var label = formatDayLabelET( slot.start_iso, timezone );
			var time  = formatTimeRangeET( slot.start_iso, slot.end_iso, timezone );
			if ( ! groups[ label ] ) {
				groups[ label ] = { label: label, slots: [] };
				order.push( label );
			}
			groups[ label ].slots.push( {
				start_iso: slot.start_iso,
				end_iso:   slot.end_iso,
				timeLabel: time
			} );
		} );
		return order.map( function ( k ) { return groups[ k ]; } );
	}

	/**
	 * Best-effort locale resolver. Prefers the browser's runtime language
	 * (so a customer with a French browser sees French weekday names) and
	 * falls back to the document's lang attribute, then to undefined which
	 * lets `Intl` pick the default. Passing `undefined` (NOT `'en-US'`) is
	 * the documented way to tell Intl "use the user's locale".
	 */
	function browserLocale() {
		if ( typeof navigator !== 'undefined' ) {
			if ( Array.isArray( navigator.languages ) && navigator.languages.length ) {
				return navigator.languages[ 0 ];
			}
			if ( navigator.language ) { return navigator.language; }
		}
		if ( typeof document !== 'undefined' && document.documentElement && document.documentElement.lang ) {
			return document.documentElement.lang;
		}
		return undefined;
	}

	function formatDayLabelET( iso, timezone ) {
		try {
			return new Date( iso ).toLocaleDateString( browserLocale(), {
				weekday: 'short',
				month:   'short',
				day:     'numeric',
				timeZone: timezone || 'America/New_York'
			} );
		} catch ( e ) { return String( iso ); }
	}
	function formatTimeRangeET( startIso, endIso, timezone ) {
		try {
			var locale = browserLocale();
			var s = new Date( startIso );
			var label = s.toLocaleTimeString( locale, {
				hour:   'numeric',
				minute: '2-digit',
				timeZone: timezone || 'America/New_York'
			} );
			if ( endIso ) {
				var e = new Date( endIso );
				label += ' – ' + e.toLocaleTimeString( locale, {
					hour:   'numeric',
					minute: '2-digit',
					timeZone: timezone || 'America/New_York'
				} );
			}
			return label;
		} catch ( err ) { return String( startIso ); }
	}
	function formatSlotLabelET( startIso, endIso, timezone ) {
		return formatDayLabelET( startIso, timezone ) + ' · ' + formatTimeRangeET( startIso, endIso, timezone );
	}

	// ===================================================================
	// Bootstrap
	// ===================================================================

	function init() {
		var roots = document.querySelectorAll( '[data-handik-booking-form]' );
		if ( ! roots || ! roots.length ) { return; }
		roots.forEach( function ( root ) {
			try {
				// eslint-disable-next-line no-new
				new HandikBookingForm( root );
			} catch ( err ) {
				root.innerHTML = '<div class="handik-booking-app__alert is-error" role="alert">' +
					escapeHtml( String( err && err.message ? err.message : err ) ) + '</div>';
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )( window, document );
