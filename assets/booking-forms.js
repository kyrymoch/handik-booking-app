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
		this.calMounted        = false;

		this.googleMapsPromise   = null;
		this.addressAutocomplete = null;

		// Phone-based contact lookup state. We hit /contacts/lookup once per
		// unique fully-typed phone number and cache the result so leaving and
		// re-entering the contact step doesn't spam the endpoint.
		this.lookupInFlightPhone = '';
		this.lookupLastPhone     = '';

		this.state = {
			step: 'contact',          // contact | address | cal | pick-days | review-days | success
			busy: false,
			contact: { full_name: '', phone: '', email: '' },
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
			calBookingUrl: '',
			scheduleId: 0,
			publicToken: '',
			slots: [],
			slotsLoaded: false,
			selectedSlots: [],
			confirmedDays: [],
			savedAddresses: [],   // populated by /contacts/lookup
			isReturningClient: false,
			profileContactId: 0
		};

		this.applyAppearance();
		this.ensureNotificationsRoot();
		this.render();
	}

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
		if ( 'cal' === this.state.step && ! this.calMounted ) {
			this.mountCalEmbed();
		}
	};

	HandikBookingForm.prototype.stepTitle = function () {
		switch ( this.state.step ) {
			case 'contact':      return this.t( 'contactTitle' );
			case 'address':      return this.t( 'addressTitle' );
			case 'cal':          return this.t( 'calTitle' );
			case 'pick-days':    return this.t( 'pickDaysTitle' );
			case 'review-days':  return this.t( 'reviewTitle' );
			case 'success':      return this.t( 'successHeading' );
			default:             return String( this.preset.form_title || '' );
		}
	};

	HandikBookingForm.prototype.stepBody = function () {
		switch ( this.state.step ) {
			case 'contact':     return this.contactMarkup();
			case 'address':     return this.addressMarkup();
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
		var c = this.state.contact;
		var t = this.state.touched;
		var nameError  = t.full_name && ! validateFullName( c.full_name ) ? this.t( 'errorRequired' ) : '';
		var phoneError = t.phone && ! validatePhone( c.phone ) ? this.t( 'errorPhone' ) : '';
		var emailError = t.email && '' !== c.email && ! validateEmail( c.email ) ? this.t( 'errorEmail' ) : '';

		return [
			'<p class="handik-booking-app__intro">', escapeHtml( this.t( 'contactIntro' ) ), '</p>',
			this.fieldMarkup( {
				model: 'contact.full_name',
				label: this.t( 'fullNameLabel' ),
				type: 'text',
				value: c.full_name,
				autocomplete: 'name',
				error: nameError,
				required: true,
				inputId: 'handik-form-full-name'
			} ),
			'<div class="handik-grid-2">',
				this.fieldMarkup( {
					model: 'contact.phone',
					label: this.t( 'phoneLabel' ),
					type: 'tel',
					value: c.phone,
					autocomplete: 'tel',
					inputmode: 'tel',
					error: phoneError,
					required: true,
					inputId: 'handik-form-phone'
				} ),
				this.fieldMarkup( {
					model: 'contact.email',
					label: this.t( 'emailLabel' ),
					type: 'email',
					value: c.email,
					autocomplete: 'email',
					inputmode: 'email',
					error: emailError,
					required: false,
					inputId: 'handik-form-email'
				} ),
			'</div>',
			this.footerActionsMarkup( {
				continueAction: 'contact-next',
				continueLabel: this.t( 'continueLabel' ),
				hideBack: true,
				continueMuted: ! this.contactValid()
			} )
		].join( '' );
	};

	HandikBookingForm.prototype.addressMarkup = function () {
		var a = this.state.address;
		var hasMaps = !! this.config.googleMapsApiKey;
		// Continue gating: when Maps is configured, require a Places-verified
		// address (is_valid). Without Maps, accept any non-empty value.
		var addressFilled = '' !== String( a.address_full || '' ).trim();
		var continueMuted = hasMaps ? ! a.is_valid : ! addressFilled;
		// Inline error: only after the user typed something but we can't
		// confirm it via Places — same wording as the main form.
		var addressError = ( hasMaps && addressFilled && ! a.is_valid )
			? this.t( 'errorAddressInvalid' )
			: '';

		// Saved addresses: returning customer has prior addresses. Render the
		// same <select> dropdown the main app uses (NOT a button list).
		var savedMarkup = '';
		var savedAddresses = Array.isArray( this.state.savedAddresses ) ? this.state.savedAddresses : [];
		if ( savedAddresses.length ) {
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

		// Native autofill suppression — prevents Chrome's address heuristic
		// from competing with Google Places for keystrokes (which made the
		// dropdown un-clickable in earlier builds).
		var addressAttrs = hasMaps
			? 'autocomplete="new-password" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-form-type="other" name="handik_form_location_query"'
			: 'autocomplete="street-address" name="handik_form_location_query"';
		var unitAttrs = 'autocomplete="new-password" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-form-type="other" name="handik_form_unit_detail"';

		return [
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
				inputId: 'handik-form-address-unit',
				required: false,
				rawAttrs: unitAttrs,
				skipName: true
			} ),
			this.footerActionsMarkup( {
				backAction: 'address-back',
				continueAction: 'address-next',
				continueLabel: this.t( 'continueLabel' ),
				continueMuted: continueMuted
			} )
		].join( '' );
	};

	HandikBookingForm.prototype.calMarkup = function () {
		// Skeleton matches main app's `.handik-skeleton--calendar`.
		var skeleton = '<div class="handik-skeleton handik-skeleton--calendar" aria-hidden="true">' +
			'<div class="handik-skeleton__bar handik-skeleton__bar--header"></div>' +
			'<div class="handik-skeleton__grid">' +
				new Array( 9 ).join( '<div class="handik-skeleton__cell"></div>' ) + '<div class="handik-skeleton__cell"></div>' +
			'</div></div>';

		return [
			'<p class="handik-booking-app__intro">', escapeHtml( this.t( 'calIntro' ) ), '</p>',
			'<div class="handik-booking-app__booking-embed" data-handik-cal-embed>', skeleton, '</div>',
			this.footerActionsMarkup( {
				backAction: 'cal-back',
				hideContinue: true
			} )
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
		return this.isProject
			? [ 'contact', 'address', 'pick-days', 'review-days', 'success' ]
			: [ 'contact', 'address', 'cal', 'success' ];
	};

	HandikBookingForm.prototype.progressMarkup = function () {
		if ( 'success' === this.state.step ) {
			return '';
		}
		var steps = this.applicableSteps();
		var activeIndex = Math.max( 0, steps.indexOf( this.state.step ) );
		var html = '<div class="handik-global-progress"><ol class="handik-progress-dots" aria-label="' + escapeAttr( this.t( 'progressLabel' ) ) + '">';
		steps.forEach( function ( step, idx ) {
			var classes = '';
			if ( idx <= activeIndex ) { classes += ' is-done'; }
			if ( idx === activeIndex ) { classes += ' is-current'; }
			html += '<li class="' + classes.trim() + '"></li>';
		} );
		html += '</ol></div>';
		return html;
	};

	HandikBookingForm.prototype.disclaimerMarkup = function () {
		// Mirror main app's "Stuck? Start a new booking · Open the booking
		// page directly" footer link.
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
		if ( opts.rawAttrs ) { inputAttrs.push( opts.rawAttrs ); }

		return '<label class="' + fieldClass + '" for="' + escapeAttr( inputId ) + '">' +
				'<span>' + escapeHtml( opts.label ) + '</span>' +
				'<input ' + inputAttrs.join( ' ' ) + '>' +
				( opts.error ? '<span class="handik-field__error" role="alert">' + escapeHtml( opts.error ) + '</span>' : '' ) +
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
		if ( 'contact.phone' === model ) {
			value = formatPhoneAsYouType( value );
			input.value = value;
		}
		if ( 'address.address_full' === model ) {
			// User edited the address text directly — invalidate the previous
			// Places verification so they have to re-pick from suggestions.
			this.state.address.is_valid  = false;
			this.state.address.address_id = 0;
		}
		this.setFieldValue( model, value );
	};

	HandikBookingForm.prototype.onBlur = function ( input ) {
		var model = input.getAttribute( 'data-model' );
		if ( ! model ) { return; }
		var key = model.split( '.' ).pop();
		this.state.touched[ key ] = true;
		// Re-render contact step on blur to surface inline errors. Other
		// steps don't need a re-render — would lose focus.
		if ( 'contact' === this.state.step || ( 'address' === this.state.step && 'address_full' === key ) ) {
			this.render();
		}
	};

	HandikBookingForm.prototype.setFieldValue = function ( path, value ) {
		var parts = path.split( '.' );
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
			case 'contact-next':
				this.state.touched.full_name = true;
				this.state.touched.phone     = true;
				this.state.touched.email     = true;
				if ( ! this.contactValid() ) {
					this.render();
					this.toast( 'warning', this.t( 'errorRequired' ) );
					return;
				}
				this.lookupContactAndAdvance();
				break;
			case 'address-back':
				this.go( 'contact' );
				break;
			case 'address-next':
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
				if ( this.isProject ) {
					this.openProject();
				} else {
					this.submitDirect();
				}
				break;
			case 'cal-back':
				this.go( 'address' );
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
				this.restart();
				break;
		}
	};

	HandikBookingForm.prototype.go = function ( step ) {
		this.state.step = step;
		this.render();
	};

	/**
	 * Reset to a fresh contact step. Mirrors the main app's "Start a new
	 * booking" link in the Stuck disclaimer.
	 */
	HandikBookingForm.prototype.restart = function () {
		this.state.step             = 'contact';
		this.state.busy             = false;
		this.state.contact          = { full_name: '', phone: '', email: '' };
		this.state.address          = {
			address_id: 0, address_full: '', address_unit: '', address_line_1: '',
			city: '', state: '', zip_code: '', is_valid: false
		};
		this.state.touched          = {};
		this.state.directRequestId  = 0;
		this.state.calBookingUrl    = '';
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
		this.calMounted             = false;
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

	/**
	 * Look up the customer by phone number against the existing CRM. If we
	 * find a match, prefill name + email and stash any saved addresses so
	 * the address step can offer them. Always advances to the address step
	 * — even when the lookup fails — so a network blip never strands the
	 * customer on the contact screen.
	 */
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
				self.state.directRequestId = parseInt( res.request_id, 10 ) || 0;
				self.state.calBookingUrl   = String( res.cal_booking_url || '' );
				self.calMounted = false;
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

	HandikBookingForm.prototype.busy = function ( on ) {
		this.state.busy = !! on;
		var primaries = this.shell ? this.shell.querySelectorAll( '.handik-btn.is-primary, .handik-btn.is-pending' ) : [];
		primaries.forEach( function ( b ) {
			if ( on ) {
				b.setAttribute( 'aria-busy', 'true' );
				b.setAttribute( 'aria-disabled', 'true' );
			} else {
				b.removeAttribute( 'aria-busy' );
			}
		} );
	};

	// ===================================================================
	// Cal embed (matches main app's window.Cal API)
	// ===================================================================

	HandikBookingForm.prototype.loadCalScript = function ( origin ) {
		if ( window.Cal && 'function' === typeof window.Cal ) {
			return Promise.resolve( window.Cal );
		}
		if ( this.calEmbedPromise ) {
			return this.calEmbedPromise;
		}
		var embedOrigin = origin || 'https://app.cal.com';
		var embedUrl    = embedOrigin.replace( /\/+$/, '' ) + '/embed/embed.js';

		this.calEmbedPromise = new Promise( function ( resolve, reject ) {
			if ( window.Cal && 'function' === typeof window.Cal ) {
				resolve( window.Cal );
				return;
			}
			window.Cal = window.Cal || function () {
				var cal = window.Cal;
				cal.q = cal.q || [];
				cal.q.push( arguments );
			};
			var existing = document.getElementById( CAL_EMBED_SCRIPT_ID );
			if ( ! existing ) {
				var script = document.createElement( 'script' );
				script.id    = CAL_EMBED_SCRIPT_ID;
				script.async = true;
				script.defer = true;
				script.src   = embedUrl;
				script.onload  = function () { resolve( window.Cal ); };
				script.onerror = function () { reject( new Error( 'Cal embed script failed to load.' ) ); };
				document.head.appendChild( script );
			} else {
				existing.addEventListener( 'load',  function () { resolve( window.Cal ); }, { once: true } );
				existing.addEventListener( 'error', function () { reject( new Error( 'Cal embed script failed to load.' ) ); }, { once: true } );
			}
		} );
		return this.calEmbedPromise;
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
		var parsed = this.parseCalEmbedConfig();
		if ( ! parsed ) {
			container.innerHTML = '<div class="handik-booking-app__alert is-error" role="alert">' + escapeHtml( this.t( 'calNotReady' ) ) + '</div>';
			return;
		}

		var fallbackTimer = window.setTimeout( function () {
			if ( self.state.calBookingUrl ) {
				container.innerHTML = '<div class="handik-booking-app__booking-direct">' +
					'<a class="handik-btn is-primary" href="' + escapeAttr( self.state.calBookingUrl ) + '" target="_blank" rel="noopener noreferrer">' +
					'<span class="handik-btn__label">' + escapeHtml( self.t( 'openInNewTab' ) ) + '</span></a></div>';
			}
		}, CAL_EMBED_TIMEOUT_MS );

		this.loadCalScript( parsed.origin )
			.then( function () {
				self.calMounted = true;
				container.innerHTML = '<div class="handik-booking-app__booking-frame-wrap" data-handik-cal-frame></div>';
				var namespace = 'handik_form_' + self.instanceId;
				self.calEmbedNamespace = namespace;
				window.__handikCalNamespaces = window.__handikCalNamespaces || {};
				if ( ! window.__handikCalNamespaces[ namespace ] ) {
					window.Cal( 'init', namespace, { origin: parsed.origin } );
					window.__handikCalNamespaces[ namespace ] = true;
				}
				var ns = window.Cal.ns && window.Cal.ns[ namespace ] ? window.Cal.ns[ namespace ] : window.Cal;
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
		this.api(
			'POST',
			'forms/direct/' + encodeURIComponent( this.state.directRequestId ) + '/capture',
			{ booking_payload: detail || {} }
		)
			.then( function () {
				self.state.confirmedDays = [];
				self.go( 'success' );
			} )
			.catch( function ( err ) {
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
				self.render();
			} );
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

	function parseConfig( root ) {
		var node = root.querySelector( '[data-handik-booking-form-config]' );
		if ( ! node ) { throw new Error( 'Missing booking form config' ); }
		try { return JSON.parse( node.textContent || '{}' ); }
		catch ( err ) { throw new Error( 'Could not parse booking form config' ); }
	}

	function validateFullName( v ) {
		return /^[\p{L}][\p{L}\s'-]*$/u.test( String( v == null ? '' : v ).trim() );
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
		return {
			address_full:   place.formatted_address || '',
			address_line_1: lineOne || place.formatted_address || '',
			address_unit:   subpremise || '',
			city:  value( 'locality', false ) || value( 'postal_town', false ) || value( 'sublocality_level_1', false ),
			state: value( 'administrative_area_level_1', true ),
			zip_code: value( 'postal_code', false ),
			is_valid: !! ( place.formatted_address && lineOne && place.geometry && place.geometry.location )
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

	function formatDayLabelET( iso, timezone ) {
		try {
			return new Date( iso ).toLocaleDateString( 'en-US', {
				weekday: 'short',
				month:   'short',
				day:     'numeric',
				timeZone: timezone || 'America/New_York'
			} );
		} catch ( e ) { return String( iso ); }
	}
	function formatTimeRangeET( startIso, endIso, timezone ) {
		try {
			var s = new Date( startIso );
			var label = s.toLocaleTimeString( 'en-US', {
				hour:   'numeric',
				minute: '2-digit',
				timeZone: timezone || 'America/New_York'
			} );
			if ( endIso ) {
				var e = new Date( endIso );
				label += ' – ' + e.toLocaleTimeString( 'en-US', {
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
