/**
 * Public SPA for the Additional Booking Forms module.
 *
 * Handles two presets:
 *   - direct_cal_booking → Contact → Address → Cal.com iframe.
 *   - project_work_days  → Contact → Address → Multi-day picker → Review →
 *                          Confirm (server creates N Cal.com bookings).
 *
 * Mounts into [data-handik-booking-form-shell] using a JSON config blob in
 * [data-handik-booking-form-config]. Vanilla JS, no dependencies, no build
 * step — matches the existing plugin convention.
 */
( function ( window, document ) {
	'use strict';

	function HandikBookingForm( root ) {
		this.root = root;
		var configNode = root.querySelector( '[data-handik-booking-form-config]' );
		if ( ! configNode ) {
			throw new Error( 'Missing booking form config' );
		}
		try {
			this.config = JSON.parse( configNode.textContent || '{}' );
		} catch ( err ) {
			throw new Error( 'Could not parse booking form config' );
		}
		this.shell = root.querySelector( '[data-handik-booking-form-shell]' );
		this.preset = this.config.preset || {};
		this.i18n = this.config.i18n || {};
		this.formType = String( this.preset.form_type || '' );
		this.requiredDays = parseInt( this.preset.required_days, 10 ) || 0;

		this.state = {
			step: 'contact',
			busy: false,
			error: '',
			contact: { full_name: '', phone: '', email: '' },
			address: { address_full: '', address_unit: '' },
			touched: {},
			directRequestId: 0,
			calBookingUrl: '',
			scheduleId: 0,
			publicToken: '',
			slots: [],
			selectedSlots: [],
			missingSlots: []
		};

		this.render();
	}

	HandikBookingForm.prototype.t = function ( key ) {
		return this.i18n[ key ] || key;
	};

	HandikBookingForm.prototype.go = function ( step ) {
		this.state.step = step;
		this.state.error = '';
		this.render();
		// Scroll the form into view + move focus to the heading for a11y.
		var anchor = this.root.querySelector( 'h2' );
		if ( anchor ) {
			anchor.setAttribute( 'tabindex', '-1' );
			try {
				anchor.focus();
			} catch ( e ) { /* ignore */ }
		}
	};

	HandikBookingForm.prototype.setBusy = function ( busy ) {
		this.state.busy = !! busy;
		this.render();
	};

	HandikBookingForm.prototype.setError = function ( msg ) {
		this.state.error = String( msg || '' );
		this.render();
	};

	// ---------- top-level render ----------

	HandikBookingForm.prototype.render = function () {
		var html = '';
		html += '<div class="handik-booking-form__title"><h2 tabindex="-1">' + escapeHtml( String( this.preset.form_title || '' ) ) + '</h2></div>';

		if ( this.state.error ) {
			html += '<div class="handik-booking-form__error" role="alert">' + escapeHtml( this.state.error ) + '</div>';
		}

		switch ( this.state.step ) {
			case 'contact':
				html += this.renderContact();
				break;
			case 'address':
				html += this.renderAddress();
				break;
			case 'cal':
				html += this.renderCalIframe();
				break;
			case 'pick-days':
				html += this.renderPickDays();
				break;
			case 'review-days':
				html += this.renderReviewDays();
				break;
			case 'success':
				html += this.renderSuccess();
				break;
			case 'project-success':
				html += this.renderProjectSuccess();
				break;
			default:
				html += '<p>' + escapeHtml( this.t( 'genericError' ) ) + '</p>';
		}
		this.shell.innerHTML = html;
		this.bindEvents();
	};

	HandikBookingForm.prototype.bindEvents = function () {
		var self = this;
		var form = this.shell.querySelector( '[data-form]' );
		if ( form ) {
			form.addEventListener( 'submit', function ( ev ) {
				ev.preventDefault();
				self.onSubmit();
			} );
		}
		this.shell.querySelectorAll( 'input[name]' ).forEach( function ( input ) {
			input.addEventListener( 'input', function () {
				self.onInput( input );
			} );
			input.addEventListener( 'blur', function () {
				self.onBlur( input );
			} );
		} );
		this.shell.querySelectorAll( '[data-action]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( ev ) {
				ev.preventDefault();
				self.onAction( btn.getAttribute( 'data-action' ), btn );
			} );
		} );
	};

	HandikBookingForm.prototype.onInput = function ( input ) {
		var name = input.name;
		var value = input.value;
		if ( 'phone' === name ) {
			value = formatPhoneAsYouType( value );
			input.value = value;
		}
		if ( name && name.indexOf( '.' ) === -1 ) {
			if ( name in this.state.contact ) {
				this.state.contact[ name ] = value;
			} else if ( name in this.state.address ) {
				this.state.address[ name ] = value;
			}
		}
	};

	HandikBookingForm.prototype.onBlur = function ( input ) {
		this.state.touched[ input.name ] = true;
		// Re-render only the field error if changed; cheap full re-render is ok here.
	};

	HandikBookingForm.prototype.onAction = function ( action, btn ) {
		if ( 'back' === action ) {
			if ( 'address' === this.state.step ) {
				this.go( 'contact' );
				return;
			}
			if ( 'pick-days' === this.state.step ) {
				this.go( 'address' );
				return;
			}
			if ( 'review-days' === this.state.step ) {
				this.go( 'pick-days' );
				return;
			}
		}
		if ( 'continue' === action ) {
			this.onSubmit();
			return;
		}
		if ( 'pick-replacement' === action ) {
			this.state.missingSlots = [];
			this.go( 'pick-days' );
			return;
		}
		if ( 'toggle-slot' === action ) {
			this.toggleSlot( btn.getAttribute( 'data-slot-start' ), btn.getAttribute( 'data-slot-end' ) );
			return;
		}
		if ( 'review-days' === action ) {
			if ( this.state.selectedSlots.length !== this.requiredDays ) {
				return;
			}
			this.saveSelection();
			return;
		}
		if ( 'confirm-days' === action ) {
			this.confirmSchedule();
			return;
		}
	};

	HandikBookingForm.prototype.onSubmit = function () {
		if ( 'contact' === this.state.step ) {
			this.state.touched = { full_name: true, phone: true, email: true };
			if ( ! this.validateContact() ) {
				this.render();
				return;
			}
			this.go( 'address' );
			return;
		}
		if ( 'address' === this.state.step ) {
			this.state.touched.address_full = true;
			if ( '' === String( this.state.address.address_full || '' ).trim() ) {
				this.setError( this.t( 'errorRequired' ) );
				return;
			}
			if ( 'project_work_days' === this.formType ) {
				this.openProject();
			} else {
				this.submitDirect();
			}
		}
	};

	// ---------- step renderers ----------

	HandikBookingForm.prototype.renderContact = function () {
		var c = this.state.contact;
		var t = this.state.touched;
		return [
			'<form data-form class="handik-booking-form__form">',
				this.fieldText( 'full_name', this.t( 'fullNameLabel' ), c.full_name, 'name', t.full_name && ! validateFullName( c.full_name ) ? this.t( 'errorRequired' ) : '' ),
				this.fieldTel( 'phone', this.t( 'phoneLabel' ), c.phone, t.phone && ! validatePhone( c.phone ) ? this.t( 'errorPhone' ) : '' ),
				this.fieldEmail( 'email', this.t( 'emailLabel' ), c.email, t.email && '' !== c.email && ! validateEmail( c.email ) ? this.t( 'errorEmail' ) : '' ),
				this.actionsRow( false, this.t( 'continueLabel' ) ),
			'</form>'
		].join( '' );
	};

	HandikBookingForm.prototype.renderAddress = function () {
		var a = this.state.address;
		return [
			'<form data-form class="handik-booking-form__form">',
				this.fieldText( 'address_full', this.t( 'addressLabel' ), a.address_full, 'street-address', '' ),
				this.fieldText( 'address_unit', this.t( 'unitLabel' ), a.address_unit, 'address-line2', '' ),
				this.actionsRow( true, this.t( 'continueLabel' ) ),
			'</form>'
		].join( '' );
	};

	HandikBookingForm.prototype.renderCalIframe = function () {
		var url = this.state.calBookingUrl;
		if ( ! url ) {
			return '<p>' + escapeHtml( this.t( 'genericError' ) ) + '</p>';
		}
		// Cal.com embed via iframe. We don't try to listen to bookingSuccessful
		// over postMessage — the existing webhook handles status sync. Customer
		// sees the success screen only after the iframe shows Cal's confirmation.
		return [
			'<div class="handik-booking-form__cal-wrap">',
				'<iframe',
					' src="', escapeAttr( url ), '"',
					' title="', escapeAttr( this.t( 'reviewTitle' ) ), '"',
					' loading="eager"',
					' allow="payment"',
					' style="width:100%;min-height:720px;border:0;"></iframe>',
			'</div>'
		].join( '' );
	};

	HandikBookingForm.prototype.renderPickDays = function () {
		if ( ! this.state.slots.length ) {
			return '<p class="handik-booking-form__loading" aria-live="polite">' + escapeHtml( this.t( 'loading' ) ) + '</p>';
		}
		var grouped = groupSlotsByDay( this.state.slots, this.config.timezone || 'America/New_York' );
		var selectedKey = {};
		this.state.selectedSlots.forEach( function ( s ) {
			selectedKey[ s.start ] = true;
		} );
		var html = '';
		html += '<p class="handik-booking-form__hint">' + escapeHtml( ( this.t( 'pickHelper' ) || '' ).replace( '%d', this.requiredDays ) ) + '</p>';
		if ( this.state.missingSlots && this.state.missingSlots.length ) {
			html += '<div class="handik-booking-form__warning" role="alert">' + escapeHtml( this.t( 'replacementNeeded' ) ) + '</div>';
		}
		html += '<ul class="handik-booking-form__slots">';
		grouped.forEach( function ( day ) {
			html += '<li class="handik-booking-form__slot-day">';
			html += '<div class="handik-booking-form__slot-day-label">' + escapeHtml( day.label ) + '</div>';
			html += '<div class="handik-booking-form__slot-row">';
			day.slots.forEach( function ( slot ) {
				var pressed = selectedKey[ slot.start_iso ] ? 'true' : 'false';
				var cls = 'handik-booking-form__slot' + ( selectedKey[ slot.start_iso ] ? ' is-selected' : '' );
				html += '<button type="button" class="' + cls + '" data-action="toggle-slot" data-slot-start="' + escapeAttr( slot.start_iso ) + '" data-slot-end="' + escapeAttr( slot.end_iso || '' ) + '" aria-pressed="' + pressed + '">';
				html += escapeHtml( slot.timeLabel );
				html += '</button>';
			} );
			html += '</div></li>';
		} );
		html += '</ul>';

		var counter = ( this.t( 'selectionCounter' ) || '' )
			.replace( '%1$d', this.state.selectedSlots.length )
			.replace( '%2$d', this.requiredDays );
		var canProceed = this.state.selectedSlots.length === this.requiredDays;

		html += '<div class="handik-booking-form__actions">';
		html += '<button type="button" class="handik-booking-form__btn handik-booking-form__btn--secondary" data-action="back">' + escapeHtml( this.t( 'backLabel' ) ) + '</button>';
		html += '<div class="handik-booking-form__counter" aria-live="polite">' + escapeHtml( counter ) + '</div>';
		html += '<button type="button" class="handik-booking-form__btn handik-booking-form__btn--primary" data-action="review-days"' + ( canProceed ? '' : ' disabled aria-disabled="true"' ) + '>' + escapeHtml( this.t( 'continueLabel' ) ) + '</button>';
		html += '</div>';
		return html;
	};

	HandikBookingForm.prototype.renderReviewDays = function () {
		var c = this.state.contact;
		var a = this.state.address;
		var html = '';
		html += '<dl class="handik-booking-form__review">';
		html += '<dt>' + escapeHtml( this.t( 'fullNameLabel' ) ) + '</dt><dd>' + escapeHtml( c.full_name ) + '</dd>';
		html += '<dt>' + escapeHtml( this.t( 'phoneLabel' ) ) + '</dt><dd>' + escapeHtml( c.phone ) + '</dd>';
		if ( c.email ) {
			html += '<dt>' + escapeHtml( this.t( 'emailLabel' ) ) + '</dt><dd>' + escapeHtml( c.email ) + '</dd>';
		}
		html += '<dt>' + escapeHtml( this.t( 'addressLabel' ) ) + '</dt><dd>' + escapeHtml( a.address_full + ( a.address_unit ? ', ' + a.address_unit : '' ) ) + '</dd>';
		html += '</dl>';
		html += '<h3>' + escapeHtml( this.t( 'reviewTitle' ) ) + '</h3>';
		html += '<ol class="handik-booking-form__review-days">';
		this.state.selectedSlots.forEach( function ( s ) {
			html += '<li>' + escapeHtml( formatSlotLabelET( s.start, s.end || '', this.config.timezone || 'America/New_York' ) ) + '</li>';
		}, this );
		html += '</ol>';
		html += '<div class="handik-booking-form__actions">';
		html += '<button type="button" class="handik-booking-form__btn handik-booking-form__btn--secondary" data-action="back"' + ( this.state.busy ? ' disabled aria-disabled="true"' : '' ) + '>' + escapeHtml( this.t( 'backLabel' ) ) + '</button>';
		html += '<button type="button" class="handik-booking-form__btn handik-booking-form__btn--primary" data-action="confirm-days"' + ( this.state.busy ? ' disabled aria-disabled="true"' : '' ) + '>' + escapeHtml( this.t( 'confirmCta' ) ) + '</button>';
		html += '</div>';
		return html;
	};

	HandikBookingForm.prototype.renderSuccess = function () {
		return '<div class="handik-booking-form__success">' + escapeHtml( this.t( 'directSuccess' ) ) + '</div>';
	};

	HandikBookingForm.prototype.renderProjectSuccess = function () {
		var html = '<div class="handik-booking-form__success">' + escapeHtml( this.t( 'projectSuccess' ) ) + '</div>';
		if ( this.state.selectedSlots.length ) {
			html += '<ul class="handik-booking-form__success-days">';
			this.state.selectedSlots.forEach( function ( s ) {
				html += '<li>' + escapeHtml( formatSlotLabelET( s.start, s.end || '', this.config.timezone || 'America/New_York' ) ) + '</li>';
			}, this );
			html += '</ul>';
		}
		return html;
	};

	// ---------- field helpers ----------

	HandikBookingForm.prototype.fieldText = function ( name, label, value, autocomplete, error ) {
		return [
			'<label class="handik-booking-form__field">',
				'<span class="handik-booking-form__label">', escapeHtml( label ), '</span>',
				'<input type="text" name="', escapeAttr( name ), '" value="', escapeAttr( String( value || '' ) ), '" autocomplete="', escapeAttr( autocomplete || 'off' ), '" required>',
				error ? '<span class="handik-booking-form__field-error" role="alert">' + escapeHtml( error ) + '</span>' : '',
			'</label>'
		].join( '' );
	};

	HandikBookingForm.prototype.fieldTel = function ( name, label, value, error ) {
		return [
			'<label class="handik-booking-form__field">',
				'<span class="handik-booking-form__label">', escapeHtml( label ), '</span>',
				'<input type="tel" name="', escapeAttr( name ), '" value="', escapeAttr( String( value || '' ) ), '" autocomplete="tel" inputmode="tel" required>',
				error ? '<span class="handik-booking-form__field-error" role="alert">' + escapeHtml( error ) + '</span>' : '',
			'</label>'
		].join( '' );
	};

	HandikBookingForm.prototype.fieldEmail = function ( name, label, value, error ) {
		return [
			'<label class="handik-booking-form__field">',
				'<span class="handik-booking-form__label">', escapeHtml( label ), '</span>',
				'<input type="email" name="', escapeAttr( name ), '" value="', escapeAttr( String( value || '' ) ), '" autocomplete="email" inputmode="email">',
				error ? '<span class="handik-booking-form__field-error" role="alert">' + escapeHtml( error ) + '</span>' : '',
			'</label>'
		].join( '' );
	};

	HandikBookingForm.prototype.actionsRow = function ( showBack, primaryLabel ) {
		var html = '<div class="handik-booking-form__actions">';
		if ( showBack ) {
			html += '<button type="button" class="handik-booking-form__btn handik-booking-form__btn--secondary" data-action="back"' + ( this.state.busy ? ' disabled aria-disabled="true"' : '' ) + '>' + escapeHtml( this.t( 'backLabel' ) ) + '</button>';
		}
		html += '<button type="submit" class="handik-booking-form__btn handik-booking-form__btn--primary"' + ( this.state.busy ? ' disabled aria-disabled="true"' : '' ) + '>' + escapeHtml( primaryLabel ) + '</button>';
		html += '</div>';
		return html;
	};

	// ---------- validation ----------

	HandikBookingForm.prototype.validateContact = function () {
		var c = this.state.contact;
		if ( ! validateFullName( c.full_name ) ) {
			this.setError( this.t( 'errorRequired' ) );
			return false;
		}
		if ( ! validatePhone( c.phone ) ) {
			this.setError( this.t( 'errorPhone' ) );
			return false;
		}
		if ( '' !== c.email && ! validateEmail( c.email ) ) {
			this.setError( this.t( 'errorEmail' ) );
			return false;
		}
		return true;
	};

	// ---------- network ----------

	HandikBookingForm.prototype.api = function ( method, path, body ) {
		var self = this;
		var url = this.config.restBase + path.replace( /^\//, '' );
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

	HandikBookingForm.prototype.submitDirect = function () {
		var self = this;
		this.setBusy( true );
		var payload = {
			preset_slug: this.preset.preset_slug,
			full_name: this.state.contact.full_name,
			phone: this.state.contact.phone,
			email: this.state.contact.email,
			address_full: this.state.address.address_full,
			address_unit: this.state.address.address_unit,
			source_url: this.config.sourceUrl || ''
		};
		this.api( 'POST', 'forms/direct/submit?preset_slug=' + encodeURIComponent( this.preset.preset_slug ), payload )
			.then( function ( res ) {
				self.state.directRequestId = parseInt( res.request_id, 10 ) || 0;
				self.state.calBookingUrl = String( res.cal_booking_url || '' );
				self.go( 'cal' );
			} )
			.catch( function ( err ) {
				self.setError( err.message );
			} )
			.then( function () {
				self.setBusy( false );
			} );
	};

	HandikBookingForm.prototype.openProject = function () {
		var self = this;
		this.setBusy( true );
		var payload = {
			preset_slug: this.preset.preset_slug,
			full_name: this.state.contact.full_name,
			phone: this.state.contact.phone,
			email: this.state.contact.email,
			address_full: this.state.address.address_full,
			address_unit: this.state.address.address_unit,
			source_url: this.config.sourceUrl || ''
		};
		this.api( 'POST', 'forms/project/open?preset_slug=' + encodeURIComponent( this.preset.preset_slug ), payload )
			.then( function ( res ) {
				self.state.scheduleId = parseInt( res.schedule_id, 10 ) || 0;
				self.state.publicToken = String( res.public_token || '' );
				return self.fetchSlots();
			} )
			.then( function () {
				self.go( 'pick-days' );
			} )
			.catch( function ( err ) {
				self.setError( err.message );
			} )
			.then( function () {
				self.setBusy( false );
			} );
	};

	HandikBookingForm.prototype.fetchSlots = function () {
		var self = this;
		var path = 'forms/project/' + encodeURIComponent( this.state.scheduleId )
			+ '/slots?token=' + encodeURIComponent( this.state.publicToken );
		return this.api( 'GET', path ).then( function ( res ) {
			self.state.slots = Array.isArray( res.slots ) ? res.slots : [];
		} );
	};

	HandikBookingForm.prototype.toggleSlot = function ( startIso, endIso ) {
		var existing = -1;
		for ( var i = 0; i < this.state.selectedSlots.length; i++ ) {
			if ( this.state.selectedSlots[ i ].start === startIso ) {
				existing = i;
				break;
			}
		}
		if ( existing >= 0 ) {
			this.state.selectedSlots.splice( existing, 1 );
		} else {
			if ( this.state.selectedSlots.length >= this.requiredDays ) {
				return;
			}
			this.state.selectedSlots.push( { start: startIso, end: endIso || '' } );
		}
		this.render();
	};

	HandikBookingForm.prototype.saveSelection = function () {
		var self = this;
		this.setBusy( true );
		var path = 'forms/project/' + encodeURIComponent( this.state.scheduleId )
			+ '/select?token=' + encodeURIComponent( this.state.publicToken );
		this.api( 'POST', path, { selected_slots: this.state.selectedSlots } )
			.then( function () {
				self.go( 'review-days' );
			} )
			.catch( function ( err ) {
				self.setError( err.message );
			} )
			.then( function () {
				self.setBusy( false );
			} );
	};

	HandikBookingForm.prototype.confirmSchedule = function () {
		var self = this;
		this.setBusy( true );
		var path = 'forms/project/' + encodeURIComponent( this.state.scheduleId )
			+ '/confirm?token=' + encodeURIComponent( this.state.publicToken );
		this.api( 'POST', path, {} )
			.then( function () {
				self.go( 'project-success' );
			} )
			.catch( function ( err ) {
				if ( err.payload && Array.isArray( err.payload.missing ) && err.payload.missing.length ) {
					self.state.missingSlots = err.payload.missing;
					var missingKeys = {};
					err.payload.missing.forEach( function ( m ) {
						missingKeys[ m.start_iso ] = true;
					} );
					self.state.selectedSlots = self.state.selectedSlots.filter( function ( s ) {
						return ! missingKeys[ s.start ];
					} );
					return self.fetchSlots().then( function () {
						self.go( 'pick-days' );
					} );
				}
				self.setError( err.message );
			} )
			.then( function () {
				self.setBusy( false );
			} );
	};

	// ---------- pure helpers ----------

	function validateFullName( v ) {
		return /^[\p{L}][\p{L}\s'-]*$/u.test( String( v || '' ).trim() );
	}
	function validateEmail( v ) {
		return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( String( v || '' ).trim() );
	}
	function phoneDigits( v ) {
		var d = String( v || '' ).replace( /\D/g, '' );
		if ( d.length > 10 && '1' === d.charAt( 0 ) ) {
			d = d.slice( 1 );
		}
		return d.slice( 0, 10 );
	}
	function validatePhone( v ) {
		return 10 === phoneDigits( v ).length;
	}
	function formatPhoneAsYouType( v ) {
		var d = phoneDigits( v );
		if ( ! d ) {
			return '';
		}
		var parts = [];
		if ( d.length > 0 ) {
			parts.push( d.slice( 0, Math.min( 3, d.length ) ) );
		}
		if ( d.length > 3 ) {
			parts.push( d.slice( 3, Math.min( 6, d.length ) ) );
		}
		if ( d.length > 6 ) {
			parts.push( d.slice( 6, Math.min( 8, d.length ) ) );
		}
		if ( d.length > 8 ) {
			parts.push( d.slice( 8, Math.min( 10, d.length ) ) );
		}
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
	function escapeAttr( s ) {
		return escapeHtml( s );
	}

	function groupSlotsByDay( slots, timezone ) {
		var groups = {};
		var order = [];
		slots.forEach( function ( slot ) {
			var label = formatDayLabelET( slot.start_iso, timezone );
			var time = formatTimeRangeET( slot.start_iso, slot.end_iso, timezone );
			if ( ! groups[ label ] ) {
				groups[ label ] = { label: label, slots: [] };
				order.push( label );
			}
			groups[ label ].slots.push( {
				start_iso: slot.start_iso,
				end_iso: slot.end_iso,
				timeLabel: time
			} );
		} );
		return order.map( function ( k ) {
			return groups[ k ];
		} );
	}

	function formatDayLabelET( iso, timezone ) {
		try {
			var d = new Date( iso );
			return d.toLocaleDateString( 'en-US', {
				weekday: 'short',
				month: 'short',
				day: 'numeric',
				timeZone: timezone || 'America/New_York'
			} );
		} catch ( e ) {
			return String( iso );
		}
	}
	function formatTimeRangeET( startIso, endIso, timezone ) {
		try {
			var s = new Date( startIso );
			var label = s.toLocaleTimeString( 'en-US', {
				hour: 'numeric',
				minute: '2-digit',
				timeZone: timezone || 'America/New_York'
			} );
			if ( endIso ) {
				var e = new Date( endIso );
				label += ' – ' + e.toLocaleTimeString( 'en-US', {
					hour: 'numeric',
					minute: '2-digit',
					timeZone: timezone || 'America/New_York'
				} );
			}
			return label;
		} catch ( err ) {
			return String( startIso );
		}
	}
	function formatSlotLabelET( startIso, endIso, timezone ) {
		var day = formatDayLabelET( startIso, timezone );
		var time = formatTimeRangeET( startIso, endIso, timezone );
		return day + ' · ' + time;
	}

	// Bootstrap. Runs only after all prototype methods + helpers above have
	// been registered (the `this.render is not a function` bug from 2.1.9.1
	// was caused by hoisting the constructor call above the prototype
	// assignments). Defer to DOMContentLoaded if the script lands before the
	// markup, otherwise run immediately.
	function init() {
		var roots = document.querySelectorAll( '[data-handik-booking-form]' );
		if ( ! roots || ! roots.length ) {
			return;
		}
		roots.forEach( function ( root ) {
			try {
				// eslint-disable-next-line no-new
				new HandikBookingForm( root );
			} catch ( err ) {
				root.innerHTML = '<p class="handik-booking-form__error">' + escapeHtml( String( err && err.message ? err.message : err ) ) + '</p>';
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )( window, document );
