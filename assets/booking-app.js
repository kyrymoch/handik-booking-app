( function( window, document ) {
	'use strict';

	const config = window.HandikBookingAppConfig || {};
	const GOOGLE_SCRIPT_ID = 'handik-google-maps-places';
	const CAL_EMBED_SCRIPT_ID = 'handik-cal-embed-script';
	const DRAFT_STORAGE_KEY = 'handik_booking_app_draft_v1';
	const DRAFT_TTL_MS = 24 * 60 * 60 * 1000; // 24 hours.

	// Sprint 6 — verified-client cache (shared key with Additional Forms so
	// a customer who verified there is recognized here too, and vice versa).
	const VERIFIED_CLIENT_STORAGE_KEY = 'handik_verified_client_v1';
	const VERIFIED_CLIENT_TTL_MS = 30 * 24 * 60 * 60 * 1000;

	function readVerifiedClient() {
		if ( ! window.localStorage ) { return null; }
		let raw;
		try { raw = window.localStorage.getItem( VERIFIED_CLIENT_STORAGE_KEY ); }
		catch ( e ) { return null; }
		if ( ! raw ) { return null; }
		try {
			const v = JSON.parse( raw );
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
		try { window.localStorage.setItem( VERIFIED_CLIENT_STORAGE_KEY, JSON.stringify( v ) ); }
		catch ( e ) { /* quota; ignore */ }
	}

	function clearVerifiedClient() {
		if ( ! window.localStorage ) { return; }
		try { window.localStorage.removeItem( VERIFIED_CLIENT_STORAGE_KEY ); }
		catch ( e ) { /* ignore */ }
	}
	// Sprint 7 (a11y) + 2.1.15.1 hardening: shared focus trap for modals
	// (restart-confirm dialog). WCAG 2.4.3. Module-scoped stack so nested
	// dialogs don't double-handle Tab; release validates that the
	// previously focused element is still in the DOM before refocusing.
	const __handikModalTrapStack = [];
	function trapModalFocus( container ) {
		if ( ! container ) { return function () {}; }
		const previouslyFocused = document.activeElement;
		const FOCUSABLE = 'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
		function visible( el ) {
			return el && ! el.hasAttribute( 'aria-hidden' ) && ( el.offsetWidth || el.offsetHeight || el.getClientRects().length );
		}
		function getFocusable() {
			return Array.prototype.filter.call( container.querySelectorAll( FOCUSABLE ), visible );
		}
		const trap = { container: container, onKey: null };
		trap.onKey = function ( event ) {
			if ( 'Tab' !== event.key ) { return; }
			if ( __handikModalTrapStack[ __handikModalTrapStack.length - 1 ] !== trap ) { return; }
			const list = getFocusable();
			if ( ! list.length ) { event.preventDefault(); return; }
			const first = list[ 0 ];
			const last  = list[ list.length - 1 ];
			const active = document.activeElement;
			if ( event.shiftKey ) {
				if ( active === first || ! container.contains( active ) ) {
					event.preventDefault();
					last.focus();
				}
			} else if ( active === last ) {
				event.preventDefault();
				first.focus();
			}
		};
		__handikModalTrapStack.push( trap );
		document.addEventListener( 'keydown', trap.onKey, true );
		return function release() {
			document.removeEventListener( 'keydown', trap.onKey, true );
			const idx = __handikModalTrapStack.indexOf( trap );
			if ( idx > -1 ) { __handikModalTrapStack.splice( idx, 1 ); }
			if (
				previouslyFocused
				&& document.contains( previouslyFocused )
				&& 'function' === typeof previouslyFocused.focus
			) {
				try { previouslyFocused.focus(); } catch ( e ) { /* ignore */ }
			}
		};
	}

	const CAL_EMBED_TIMEOUT_MS = 15000;

	// Single status block shown while the assistant is "thinking" between
	// the user pressing Send and the assistant's first output token. The
	// timeline below is wall-clock: each entry replaces the text inside
	// the SAME block (we don't push 7 messages). At 50s the block also
	// surfaces a soft Plan B link. None of these strings ever leave the
	// browser — no API call, no chatkit thread, no handik_messages row.
	const ASSISTANT_STATUS_STAGES = [
		{ delay: 1000, text: 'Reviewing your request…' },
		{ delay: 5000, text: 'Checking the details, photos, pricing, and booking type…' },
		{ delay: 10000, text: 'Still working on it. The assistant is matching the job details to the right visit type.' },
		{ delay: 20000, text: 'This is taking a little longer than usual. The tiny robot gears are still turning.' },
		{ delay: 30000, text: 'Almost there. The assistant is preparing the time and cost recommendation.' },
		{ delay: 40000, text: 'Still thinking. The robot has not given up, it is just being very careful.' },
		{ delay: 50000, text: 'This is taking too long. You can keep waiting, or open the booking page directly and Alex will review the details before the visit.', showLink: true },
	];

	const PERSISTED_STATE_FIELDS = [
		'step',
		'isReturningClient',
		'verifiedProfile',
		'lastLookupPhone',
		'requestId',
		'draftToken',
		'selectedTasks',
		'taskSelectionMode',
		'isProject',
		'jobShape',
		'address',
		'photos',
		'shortDescription',
		'assistantThreadId',
		'contact',
		'appSessionKey'
	];

	class HandikBookingApp {
		constructor( root ) {
			this.root = root;
			this.instanceId = 'handik-app-' + Math.random().toString( 36 ).slice( 2 );
			this.notificationRootId = this.instanceId + '-notifications';
			this.bootstrap = null;
			this.addressAutocomplete = null;
			this.bookingStatusTimer = null;
			this.googleMapsPromise = null;
			this.calEmbedPromise = null;
			this.calEmbedNamespace = '';
			this.calEmbedMountKey = '';
			// Sprint 10 fix: separate "iframe is actually usable" key set
			// by the bookerReady listener; the 15s slow-load fallback uses
			// this instead of mountKey (which only proves the script ran).
			this.calEmbedReadyKey = '';
			this._lastCapturedBookingId = '';
			this.calEmbedListenerKey = '';
			this.assistantPreparationPromise = null;
			this.assistantMountPromise = null;
			this.assistantSessionPrewarmPromise = null;
			this.assistantSessionPrewarmedRequestId = 0;
			this.assistantPrewarmedSession = null;
			this.assistantPrewarmedRequestId = 0;
			this.pendingAssistantContextAnalysis = null;
			this.notificationTimers = new Map();
			this.assistantBridge = null;
			this.photoAnalysisWarmRequestId = 0;
			this.photoAnalysisWarmPromise = null;
			this.photoAnalysisWarmPromiseRequestId = 0;
			this.assistantContextDispatchPromise = null;
			this.assistantUnlockTimer = null;
			// 2.1.29.0 — per-turn assistant status block. One DOM element
			// whose copy is rotated by these timers (see startAssistantStatusBlock).
			// Cleared on assistant message detected, structured result stored,
			// onComplete, or onError.
			this.assistantStatusTimers = [];
			this.assistantStatusActive = false;
			// Issue 5: safety timer for the assistant pipeline's mount phase
			// (separate from the per-turn status block — this is "the bridge
			// itself never loaded", not "the assistant is taking too long").
			this.assistantPreparingSafetyTimer = null;
			this.savedAddressLoadingTimer = null;
			this.savedAddressLoadingProfileKey = '';
			this.state = {
				step: 'task_selection',
				isReturningClient: false,
				verifiedProfile: null,
				lastLookupPhone: '',
				requestId: 0,
				draftToken: '',
				selectedTasks: [],
				taskSelectionMode: 'overview',
				selectedTasksSheetOpen: false,
				selectedTasksSheetAnimate: false,
				selectedTasksSheetAttentionDismissed: false,
				isProject: false,
				jobShape: '',
				address: { address_id: 0, address_full: '', address_line_1: '', address_unit: '', city: '', state: '', zip_code: '', is_valid: false },
				photos: [],
				shortDescription: '',
				assistantResult: null,
				assistantResultSaved: false,
				assistantBookingUrlReady: false,
				assistantReadyForBooking: false,
				assistantRoutingPending: false,
				assistantAwaitingResponse: false,
				assistantResponseSeen: false,
				assistantFallbackMessage: '',
				assistantUserMessageSent: false,
				assistantThreadId: '',
				contact: { first_name: '', last_name: '', full_name: '', email: '', phone: '' },
				touched: { full_name: false, email: false, phone: false },
				// Sprint 6 — phone-first OTP flow.
				otpCode: '',
				otpError: '',
				otpResendDisabledUntil: 0,
				verifiedPhone: '',
				verifiedToken: '',
				phoneVerified: false,
				bookingUrl: '',
				bookingUrlLocked: false,
				bookingStatus: '',
				bookingStatusMessage: '',
				unsafeReason: '',
				appSessionKey: 'app_' + Math.random().toString( 36 ).slice( 2 ),
				message: '',
				footerHint: '',
				footerHintError: false,
				restartConfirmVisible: false,
				savedAddressLoading: false,
				lastAssistantNotice: '',
				assistantPreparing: false,
				loading: false,
				photoUploading: false,
				bookingOpened: false,
				notifications: []
			};
		}

		async init() {
			this.renderLoading();
			this.restoreDraftFromStorage();
			this.attachHistoryListener();
			// Sprint 6: try to revalidate the verified-client token before
			// the bootstrap call so a returning customer's profile is in
			// state when the first render runs.
			try { await this.tryRestoreVerifiedClient(); } catch ( e ) { /* ignore */ }
			// Sprint 9 fix: if the cache restored the verified state and a
			// stored draft happens to be parked on `contact_details` /
			// `otp_verify` (because the customer abandoned mid-OTP last
			// time), bump the step to the next non-skipped one — those
			// two are now removed from `applicableSteps()`, so leaving
			// them in `state.step` would render an empty dot timeline.
			if ( this.state.phoneVerified && ( 'contact_details' === this.state.step || 'otp_verify' === this.state.step ) ) {
				this.state.step = 'address_details';
			}
			try {
				this.bootstrap = await this.api( 'app/bootstrap', {}, 'GET' );
				if ( this.bootstrap.verified_profile ) {
					this.state.verifiedProfile = this.bootstrap.verified_profile;
					this.state.isReturningClient = true;
				}
				this.render();
				this.replaceHistoryState( this.state.step );
				this.focusStepHeading();
				// Sprint 10 fix: deferred Welcome-back toast for the
				// 30-day cache-restore path. tryRestoreVerifiedClient
				// queues the flag, init shows it after first render so
				// the toast doesn't get clobbered by the loading overlay
				// and isn't surfaced to brand-new customers.
				if ( this._pendingWelcomeBackToast ) {
					this._pendingWelcomeBackToast = false;
					this.notify(
						'info',
						'',
						( config.strings && config.strings.welcomeBack ) || 'Welcome back — we found your saved details.',
						3600
					);
				}
			} catch ( error ) {
				this.root.innerHTML = '<div class="handik-booking-app__shell"><div class="handik-booking-app__alert is-error" role="alert">' + this.escape( error.message ) + '</div></div>';
			}
		}

		focusStepHeading() {
			// Sprint 10 (a11y) → 2.1.20 fix: announce step changes via the
			// live region only; do NOT move keyboard focus to the <h2>.
			// Owner-reported: the visible focus ring on the heading was
			// distracting (the outline stayed until the user clicked
			// anywhere), and the parity-decision in booking-forms.js
			// already stopped focusing for the same reason — moving
			// focus dismissed mobile keyboards mid-typing and confused
			// screen magnifiers. The aria-live announcer below is enough
			// for assistive tech; sighted users get the smooth-scroll +
			// the rendered heading.
			window.requestAnimationFrame( () => {
				const heading = this.root.querySelector( '.handik-booking-app__screen-header h2' );
				if ( ! heading ) {
					return;
				}
				this.announceStep( heading.textContent || '' );
			} );
		}

		announceStep( text ) {
			// Polite live region for step changes — created lazily, sits
			// outside the SPA root so a re-render doesn't blow it away.
			if ( ! this._stepAnnouncer ) {
				const node = document.createElement( 'div' );
				node.setAttribute( 'aria-live', 'polite' );
				node.setAttribute( 'aria-atomic', 'true' );
				node.className = 'screen-reader-text handik-booking-app__step-announcer';
				node.style.cssText = 'position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);';
				document.body.appendChild( node );
				this._stepAnnouncer = node;
			}
			// Brief blank ensures repeated identical text still re-announces.
			this._stepAnnouncer.textContent = '';
			window.setTimeout( () => {
				this._stepAnnouncer.textContent = String( text || '' ).trim();
			}, 30 );
		}

		// --- Browser history ---

		attachHistoryListener() {
			if ( this._popstateBound ) {
				return;
			}
			this._popstateBound = true;
			window.addEventListener( 'popstate', ( event ) => {
				const data = event && event.state ? event.state : null;
				if ( ! data || ! data.handikBookingApp || data.instanceId !== this.instanceId ) {
					return;
				}
				if ( ! data.step || data.step === this.state.step ) {
					return;
				}
				this._navigatingFromHistory = true;
				this.goTo( data.step );
				this._navigatingFromHistory = false;
			} );
		}

		replaceHistoryState( step ) {
			if ( ! window.history || 'function' !== typeof window.history.replaceState ) {
				return;
			}
			try {
				window.history.replaceState(
					{ handikBookingApp: true, instanceId: this.instanceId, step: step },
					'',
					window.location.href
				);
			} catch ( e ) {
				// SecurityError on file:// or some restricted iframes — fail silently.
			}
		}

		pushHistoryState( step ) {
			if ( this._navigatingFromHistory ) {
				return;
			}
			if ( ! window.history || 'function' !== typeof window.history.pushState ) {
				return;
			}
			try {
				window.history.pushState(
					{ handikBookingApp: true, instanceId: this.instanceId, step: step },
					'',
					window.location.href
				);
			} catch ( e ) {
				// Ignore — history is best-effort.
			}
		}

		// --- Draft persistence (localStorage) ---

		saveDraftToStorage() {
			if ( ! window.localStorage ) {
				return;
			}
			// Don't persist after a successful booking (we clear via clearDraftStorage there).
			const persisted = {};
			PERSISTED_STATE_FIELDS.forEach( ( key ) => {
				if ( Object.prototype.hasOwnProperty.call( this.state, key ) ) {
					persisted[ key ] = this.state[ key ];
				}
			} );
			const envelope = {
				version: config.version || '0',
				savedAt: Date.now(),
				state: persisted
			};
			try {
				window.localStorage.setItem( DRAFT_STORAGE_KEY, JSON.stringify( envelope ) );
			} catch ( e ) {
				// Quota exceeded or storage disabled — nothing we can do.
			}
		}

		restoreDraftFromStorage() {
			if ( ! window.localStorage ) {
				return;
			}
			let raw;
			try {
				raw = window.localStorage.getItem( DRAFT_STORAGE_KEY );
			} catch ( e ) {
				return;
			}
			if ( ! raw ) {
				return;
			}
			let envelope;
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
			// Invalidate on plugin upgrades.
			if ( config.version && envelope.version !== config.version ) {
				this.clearDraftStorage();
				return;
			}
			// TTL check.
			if ( ! envelope.savedAt || Date.now() - envelope.savedAt > DRAFT_TTL_MS ) {
				this.clearDraftStorage();
				return;
			}
			// Don't restore if already on the terminal booking screen — user may
			// have closed the tab right after success and we don't want to
			// re-show stale draft.
			if ( 'booking' === envelope.state.step && envelope.state.bookingStatus === 'booked' ) {
				this.clearDraftStorage();
				return;
			}
			PERSISTED_STATE_FIELDS.forEach( ( key ) => {
				if ( Object.prototype.hasOwnProperty.call( envelope.state, key ) ) {
					this.state[ key ] = envelope.state[ key ];
				}
			} );
		}

		clearDraftStorage() {
			if ( ! window.localStorage ) {
				return;
			}
			try {
				window.localStorage.removeItem( DRAFT_STORAGE_KEY );
			} catch ( e ) {
				// no-op
			}
		}

		_scheduleDraftSave() {
			if ( this._draftSaveTimer ) {
				window.clearTimeout( this._draftSaveTimer );
			}
			this._draftSaveTimer = window.setTimeout( () => {
				this._draftSaveTimer = null;
				this.saveDraftToStorage();
			}, 500 );
		}

		renderLoading() {
			this.root.innerHTML = '<div class="handik-booking-app__shell"><div class="handik-booking-app__loading">' + this.loaderMarkup() + '</div></div>';
		}

		setAssistantPreparingState( isPreparing ) {
			// Issue 3 fix: the previous version DOM-injected the overlay only
			// for the assistant step, which was a race condition with render().
			// Now the overlay is always part of the rendered markup (see
			// assistantMarkup() / screen()) and we just toggle visibility.
			this.state.assistantPreparing = !! isPreparing;
			if ( 'assistant' !== this.state.step ) {
				return;
			}

			const body = this.root.querySelector( '.handik-booking-app__screen-body' );
			if ( ! body ) {
				return;
			}
			body.classList.toggle( 'is-assistant-preparing', !! isPreparing );

			// Stop the safety timer if the assistant is now ready.
			if ( ! isPreparing && this.assistantPreparingSafetyTimer ) {
				window.clearTimeout( this.assistantPreparingSafetyTimer );
				this.assistantPreparingSafetyTimer = null;
			}
		}

		loaderMarkup( message ) {
			return '<div class="sp sp-loadbar" aria-hidden="true"></div><h5>' + this.escape( message || 'Loading' ) + '</h5>';
		}

		async api( path, data, method, formData ) {
			const options = {
				method: method || 'POST',
				credentials: 'same-origin',
				headers: {}
			};

			if ( config.restNonce ) {
				options.headers['X-WP-Nonce'] = config.restNonce;
			}

			if ( formData ) {
				options.body = formData;
			} else if ( 'GET' !== options.method ) {
				options.headers['Content-Type'] = 'application/json';
				options.body = JSON.stringify( data || {} );
			}

			// Only retry idempotent GETs. POSTs (booking-capture, verify, etc.) MUST NOT
			// be retried automatically — that would risk double-booking or duplicate codes.
			const shouldRetry = 'GET' === options.method && ! formData;
			const maxAttempts = shouldRetry ? 3 : 1;
			const backoffs = [ 1000, 2000, 4000 ];
			let lastError = null;

			for ( let attempt = 0; attempt < maxAttempts; attempt++ ) {
				try {
					const response = await window.fetch( config.restBase + path, options );
					const payload = await response.json().catch( function() {
						return {};
					} );
					if ( ! response.ok ) {
						const error = new Error( payload.message || payload.error || 'Request failed' );
						error.status = response.status;
						// 5xx are worth retrying; 4xx (auth, validation) are not.
						if ( shouldRetry && response.status >= 500 && attempt < maxAttempts - 1 ) {
							lastError = error;
							await this._sleep( backoffs[ attempt ] || 4000 );
							continue;
						}
						throw error;
					}
					return payload;
				} catch ( error ) {
					lastError = error;
					// Non-network error already thrown above; if we got here from fetch()
					// rejection, that's a network error and is retryable.
					const isNetworkError = ! error.status;
					if ( shouldRetry && isNetworkError && attempt < maxAttempts - 1 ) {
						await this._sleep( backoffs[ attempt ] || 4000 );
						continue;
					}
					throw error;
				}
			}

			throw lastError || new Error( 'Request failed' );
		}

		_sleep( ms ) {
			return new Promise( ( resolve ) => window.setTimeout( resolve, ms ) );
		}

		withTimeout( promise, timeoutMs, label ) {
			return new Promise( ( resolve, reject ) => {
				let settled = false;
				const timer = window.setTimeout( () => {
					if ( settled ) {
						return;
					}
					settled = true;
					reject( new Error( ( label || 'Operation' ) + ' timed out.' ) );
				}, timeoutMs );

				Promise.resolve( promise ).then( ( value ) => {
					if ( settled ) {
						return;
					}
					settled = true;
					window.clearTimeout( timer );
					resolve( value );
				} ).catch( ( error ) => {
					if ( settled ) {
						return;
					}
					settled = true;
					window.clearTimeout( timer );
					reject( error );
				} );
			} );
		}

		async logClient( level, message, context ) {
			if ( ! config.restBase ) {
				return;
			}

			try {
				await this.api( 'client-log', {
					level: level || 'info',
					message: message || '',
					context: context || {}
				} );
			} catch ( error ) {
				// Intentionally silent; client logging must never break the booking flow.
			}
		}

		setMessage( message ) {
			if ( message ) {
				this.notify( 'info', '', message, 4200 );
			}
		}

		notify( type, title, message, duration, options ) {
			const settings = options || {};
			const item = {
				id: 'notice_' + Math.random().toString( 36 ).slice( 2 ),
				type: type || 'info',
				title: title || '',
				message: message || '',
				duration: duration || 3200,
				visible: false,
				closing: false,
				paused: false,
				remaining: duration || 3200,
				startedAt: 0,
				progress: 1,
				meta: Array.isArray( settings.meta ) ? settings.meta : [],
				action: 'function' === typeof settings.action ? settings.action : null,
				actionId: settings.actionId || '',
				actionLabel: settings.actionLabel || ''
			};
			this.state.notifications = this.state.notifications.concat( item ).slice( -4 );
			this.renderNotifications();
			window.requestAnimationFrame( () => {
				this.updateNotification( item.id, { visible: true } );
				this.resumeNotification( item.id );
			} );
		}

		updateNotification( id, patch ) {
			let changed = false;
			this.state.notifications = this.state.notifications.map( ( item ) => {
				if ( item.id !== id ) {
					return item;
				}
				changed = true;
				return Object.assign( {}, item, patch || {} );
			} );

			if ( changed ) {
				this.renderNotifications();
			}
		}

		syncNotificationTiming() {
			const now = Date.now();
			this.state.notifications = this.state.notifications.map( ( item ) => {
				if ( item.paused || item.closing || ! item.startedAt ) {
					return item;
				}

				const elapsed = Math.max( 0, now - item.startedAt );
				const remaining = Math.max( 0, ( item.remaining || item.duration || 3200 ) - elapsed );
				const duration = Math.max( 1, item.duration || 3200 );
				return Object.assign( {}, item, {
					remaining: remaining,
					progress: Math.max( 0, Math.min( 1, remaining / duration ) )
				} );
			} );
		}

		pauseNotification( id ) {
			const timer = this.notificationTimers.get( id );
			const item = this.state.notifications.find( ( entry ) => entry.id === id );
			if ( ! item || item.paused || item.closing ) {
				return;
			}

			if ( timer ) {
				window.clearTimeout( timer );
				this.notificationTimers.delete( id );
			}

			const elapsed = item.startedAt ? Date.now() - item.startedAt : 0;
			this.updateNotification( id, {
				paused: true,
				remaining: Math.max( 0, ( item.remaining || item.duration || 3200 ) - elapsed ),
				startedAt: 0,
				progress: Math.max( 0, Math.min( 1, Math.max( 0, ( item.remaining || item.duration || 3200 ) - elapsed ) / Math.max( 1, item.duration || 3200 ) ) )
			} );
		}

		resumeNotification( id ) {
			const item = this.state.notifications.find( ( entry ) => entry.id === id );
			if ( ! item || item.closing ) {
				return;
			}

			const existingTimer = this.notificationTimers.get( id );
			if ( existingTimer ) {
				window.clearTimeout( existingTimer );
				this.notificationTimers.delete( id );
			}

			const remaining = Math.max( 250, item.remaining || item.duration || 3200 );
			const timeout = window.setTimeout( () => {
				this.dismissNotification( id );
			}, remaining );

			this.notificationTimers.set( id, timeout );
			this.updateNotification( id, {
				paused: false,
				startedAt: Date.now(),
				remaining: remaining,
				progress: Math.max( 0, Math.min( 1, remaining / Math.max( 1, item.duration || 3200 ) ) )
			} );
		}

		dismissNotification( id ) {
			const timer = this.notificationTimers.get( id );
			if ( timer ) {
				window.clearTimeout( timer );
				this.notificationTimers.delete( id );
			}
			this.updateNotification( id, { visible: false, closing: true, paused: false } );
			window.setTimeout( () => {
				this.state.notifications = this.state.notifications.filter( ( item ) => item.id !== id );
				this.renderNotifications();
			}, 240 );
		}

		notificationIcon( type ) {
			switch ( type ) {
				case 'error':
					return '&times;';
				case 'warning':
					return '&#9888;';
				default:
					return '&#9432;';
			}
		}

		renderNotificationMeta( item ) {
			if ( ! item.meta || ! item.meta.length ) {
				return '';
			}

			return '<div class="handik-toast__meta">' + item.meta.map( ( meta ) => (
				'<span class="handik-toast__pill handik-toast__pill--' + this.escape( meta.tone || 'neutral' ) + '">' + this.escape( meta.label || '' ) + '</span>'
			) ).join( '' ) + '</div>';
		}

		renderNotificationClose( item ) {
			return '<button type="button" class="handik-toast__close" data-notification-dismiss="' + this.escape( item.id ) + '" aria-label="Dismiss notification">&times;</button>';
		}

		renderNotificationCard( item ) {
			const classes = [
				'handik-toast',
				'handik-toast--' + this.escape( item.type ),
				item.visible ? 'is-visible' : '',
				item.closing ? 'is-closing' : '',
				item.paused ? 'is-paused' : ''
			].filter( Boolean ).join( ' ' );

			const duration = Math.max( 400, item.remaining || item.duration || 3200 );
			const progressStart = Math.max( 0, Math.min( 1, item.progress || duration / Math.max( duration, item.duration || 3200 ) ) );
			const titleMarkup = item.title ? '<strong>' + this.escape( item.title ) + '</strong>' : '';
			const messageMarkup = item.message ? '<span>' + this.escape( item.message ) + '</span>' : '';
			const isError = 'error' === item.type;
			const ariaRole = isError ? 'alert' : 'status';
			const ariaLive = isError ? 'assertive' : 'polite';
			const retryMarkup = item.actionLabel && item.actionId
				? '<button type="button" class="handik-toast__action" data-notification-action="' + this.escape( item.id ) + '">' + this.escape( item.actionLabel ) + '</button>'
				: '';

			return '<div class="' + classes + '" role="' + ariaRole + '" aria-live="' + ariaLive + '" style="--handik-toast-duration:' + duration + 'ms;--handik-toast-progress-start:' + progressStart + ';" data-notification-id="' + this.escape( item.id ) + '">' +
					'<div class="handik-toast__icon" aria-hidden="true">' + this.notificationIcon( item.type ) + '</div>' +
					'<div class="handik-toast__content">' +
						this.renderNotificationMeta( item ) +
						'<div class="handik-toast__body">' +
							titleMarkup +
							messageMarkup +
						'</div>' +
						retryMarkup +
					'</div>' +
					this.renderNotificationClose( item ) +
				'</div>';
		}

		ensureNotificationRoot() {
			let root = document.getElementById( this.notificationRootId );
			if ( root ) {
				return root;
			}

			root = document.createElement( 'div' );
			root.id = this.notificationRootId;
			root.className = 'handik-booking-app__notifications';
			root.setAttribute( 'data-handik-app-notifications', this.instanceId );
			document.body.appendChild( root );
			return root;
		}

		renderNotifications() {
			const root = this.ensureNotificationRoot();
			if ( ! root ) {
				return;
			}

			this.syncNotificationTiming();
			root.innerHTML = this.state.notifications.map( ( item ) => this.renderNotificationCard( item ) ).join( '' );

			root.querySelectorAll( '.handik-toast' ).forEach( ( node ) => {
				const id = node.getAttribute( 'data-notification-id' );
				node.addEventListener( 'mouseenter', () => this.pauseNotification( id ) );
				node.addEventListener( 'mouseleave', () => this.resumeNotification( id ) );
				node.addEventListener( 'touchstart', () => this.pauseNotification( id ), { passive: true } );
				node.addEventListener( 'touchend', () => this.resumeNotification( id ), { passive: true } );
				node.addEventListener( 'touchcancel', () => this.resumeNotification( id ), { passive: true } );
			} );

			root.querySelectorAll( '[data-notification-dismiss]' ).forEach( ( button ) => {
				button.addEventListener( 'click', () => this.dismissNotification( button.getAttribute( 'data-notification-dismiss' ) ) );
			} );

			root.querySelectorAll( '[data-notification-action]' ).forEach( ( button ) => {
				button.addEventListener( 'click', () => {
					const id = button.getAttribute( 'data-notification-action' );
					const item = this.state.notifications.find( ( entry ) => entry.id === id );
					if ( item && 'function' === typeof item.action ) {
						this.dismissNotification( id );
						try {
							item.action();
						} catch ( e ) {
							// Swallow — caller is responsible for surfacing further errors.
						}
					}
				} );
			} );
		}

		showStepWarning( step, fallbackMessage ) {
			this.notify(
				'warning',
				'',
				this.stepBlockMessage( step ) || fallbackMessage || '',
				4200
			);
		}

		setFooterHint( message, isError ) {
			if ( message ) {
				this.notify( isError ? 'warning' : 'info', '', message, 4200 );
			}
			this.state.footerHint = '';
			this.state.footerHintError = false;
		}

		clearFooterHint() {
			this.state.footerHint = '';
			this.state.footerHintError = false;
		}

		setAssistantNotice( message, isError ) {
			if ( ! isError ) {
				return;
			}
			const next = String( message || '' ) + '|' + ( isError ? '1' : '0' );
			if ( ! message || this.state.lastAssistantNotice === next ) {
				return;
			}
			this.state.lastAssistantNotice = next;
			this.notify( isError ? 'warning' : 'info', '', message, isError ? 5200 : 4200 );
		}

		setAssistantContinueBusy( isBusy ) {
			const button = this.root.querySelector( '[data-action="assistant-next"]' );
			if ( button ) {
				const canContinue = this.assistantCanContinue();
				const shouldDisable = !! isBusy || ! canContinue;
				button.disabled = !! isBusy;
				// Hardcoded English: critical CTA copy must not surface a stale
				// localized "Loading..." setting inside the public app.
				button.textContent = isBusy
					? 'Loading…'
					: ( config.strings.assistantContinue || 'Book a time' );
				button.classList.remove( 'is-pending' );
				button.classList.remove( 'is-primary' );
				button.classList.add( shouldDisable ? 'is-pending' : 'is-primary' );
				button.setAttribute( 'aria-disabled', shouldDisable ? 'true' : 'false' );
			}
		}

		assistantCanContinue() {
			const gate = this.assistantGateState();
			return gate.allowed;
		}

		assistantGateState() {
			const result = this.state.assistantResult && 'object' === typeof this.state.assistantResult ? this.state.assistantResult : {};
			const state = {
				request_id: this.state.requestId,
				assistant_result_saved: !! this.state.assistantResultSaved,
				assistant_ready_for_booking: !! this.state.assistantReadyForBooking,
				booking_url_ready: !! this.state.assistantBookingUrlReady,
				enough_information: true === result.enough_information,
				unsafe: !! result.unsafe,
				has_user_message: !! this.state.assistantUserMessageSent,
				has_booking_url: !! this.state.bookingUrl,
				allowed: false,
				reason: ''
			};

			if ( ! state.has_user_message ) {
				state.reason = 'no user message';
				return state;
			}
			if ( ! state.assistant_result_saved ) {
				state.reason = 'no saved result';
				return state;
			}
			if ( ! state.enough_information ) {
				state.reason = 'enough_information false';
				return state;
			}
			if ( state.unsafe ) {
				state.reason = 'unsafe';
				return state;
			}
			if ( ! state.assistant_ready_for_booking ) {
				state.reason = 'assistant_ready_for_booking false';
				return state;
			}
			if ( ! state.booking_url_ready || ! state.has_booking_url ) {
				state.reason = 'booking_url missing';
				return state;
			}
			if ( ! result.booking_type || ! result.duration_bucket || ! result.suggested_duration_hours ) {
				state.reason = 'routing fields missing';
				return state;
			}
			if ( this.state.assistantRoutingPending ) {
				state.reason = 'assistant routing pending';
				return state;
			}

			state.allowed = !! (
				this.state.assistantResultSaved &&
				this.state.assistantBookingUrlReady &&
				this.state.assistantReadyForBooking &&
				! this.state.assistantRoutingPending &&
				this.state.bookingUrl &&
				this.state.assistantUserMessageSent &&
				true === result.enough_information &&
				! result.unsafe &&
				result.booking_type &&
				result.duration_bucket &&
				result.suggested_duration_hours
			);
			state.reason = state.allowed ? 'allowed' : 'unknown';
			return state;
		}

		assistantGateLogContext( gate ) {
			const state = gate || this.assistantGateState();
			return {
				request_id: state.request_id || this.state.requestId,
				assistant_result_saved: !! state.assistant_result_saved,
				assistant_ready_for_booking: !! state.assistant_ready_for_booking,
				booking_url_ready: !! state.booking_url_ready,
				enough_information: !! state.enough_information,
				unsafe: !! state.unsafe,
				has_user_message: !! state.has_user_message
			};
		}

		// True when the customer message is a short, content-free
		// acknowledgement ("yes", "ok", "sounds good", "book it", "let's do
		// it", …) rather than new job detail. Used only AFTER the assistant is
		// already ready to book, to keep Book a time enabled and skip another
		// recommendation cycle. Deliberately conservative: anything with a
		// question mark or that looks like it carries scope is treated as a
		// real message, so we never swallow new job details.
		isAssistantAcknowledgement( text ) {
			let normalized = String( text || '' ).toLowerCase().trim();
			if ( ! normalized ) {
				return false;
			}
			// Drop leading/trailing punctuation + emoji, collapse whitespace.
			normalized = normalized
				.replace( /[!.,……]+$/u, '' )
				.replace( /[\p{Extended_Pictographic}️]/gu, '' )
				.replace( /\s{2,}/g, ' ' )
				.trim();
			if ( ! normalized ) {
				// Message was only an emoji (e.g. 👍) — treat as acknowledgement.
				return true;
			}
			if ( normalized.includes( '?' ) ) {
				return false;
			}
			// A real follow-up that happens to start with "yes" still carries
			// scope ("yes but also paint the ceiling"). Cap length + word count.
			if ( normalized.length > 40 || normalized.split( ' ' ).length > 5 ) {
				return false;
			}
			const phrases = [
				'yes', 'yeah', 'yep', 'yup', 'ya', 'y', 'ok', 'okay', 'k', 'kk',
				'sure', 'sounds good', 'sound good', 'sounds great', 'looks good',
				'good', 'great', 'perfect', 'awesome', 'cool', 'nice',
				'book it', 'book', 'lets book', "let's book", 'lets do it',
				"let's do it", 'do it', 'go ahead', 'go', 'proceed', 'confirm',
				'confirmed', 'yes please', 'ok thanks', 'okay thanks', 'thanks',
				'thank you', 'that works', 'works for me', 'works', 'all good',
				'sounds perfect', 'lets go', "let's go",
				// Common short Russian affirmatives — a customer may reply in RU.
				'да', 'ок', 'окей', 'хорошо', 'давай', 'давайте', 'поехали',
				'го', 'отлично', 'супер', 'спасибо'
			];
			if ( phrases.includes( normalized ) ) {
				return true;
			}
			// Affirmative lead-in with a tiny tail ("yes go ahead", "ok book it").
			const leads = [ 'yes', 'yeah', 'yep', 'ok', 'okay', 'sure', 'да', 'ок' ];
			return leads.some( ( lead ) => normalized === lead || normalized.startsWith( lead + ' ' ) );
		}

		stepCanContinue( step ) {
			switch ( step ) {
				case 'task_selection':
					return !! ( this.state.selectedTasks.length || this.state.isProject );
				case 'address_details':
					// Returning customers: just a valid address is enough.
					// New customers: also require name (email stays optional).
					if ( ! this.state.address.address_full || ! this.state.address.is_valid || this.state.photoUploading ) {
						return false;
					}
					if ( ! this.state.isReturningClient ) {
						if ( ! this.validateFullName( this.state.contact.full_name ) ) { return false; }
						if ( this.state.contact.email && ! this.validateEmail( this.state.contact.email ) ) { return false; }
					}
					return true;
				case 'photos':
					return ! this.state.photoUploading;
				case 'assistant':
					return this.assistantCanContinue();
				case 'contact_details':
					// Sprint 6: contact_details is now phone-only.
					return this.validatePhone( this.state.contact.phone );
				case 'otp_verify':
					// Active when the customer has typed enough digits to verify.
					return String( this.state.otpCode || '' ).replace( /\D/g, '' ).length >= 4;
				default:
					return true;
			}
		}

		stepBlockMessage( step ) {
			const errors = config.strings.errors || {};
			switch ( step ) {
				case 'task_selection':
					return errors.selectTask || 'Check this step. Select at least one task or mark this as a project.';
				case 'address_details':
					return errors.addressRequired || 'Check this step. Choose a valid address from the address suggestions before continuing.';
				case 'photos':
					return '';
				case 'assistant':
					return errors.assistantRequired || 'Check this step. Please send the virtual assistant a short description of the job before continuing.';
				case 'contact_details':
					return this.contactValidationMessage();
				default:
					return '';
			}
		}

		validateFullName( value ) {
			const normalized = String( value || '' ).trim();
			return /^[\p{L}][\p{L}\s'.-]*$/u.test( normalized );
		}

		validateEmail( value ) {
			return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( String( value || '' ).trim() );
		}

		phoneDigits( value ) {
			let digits = String( value || '' ).replace( /\D/g, '' );
			if ( digits.length > 10 && '1' === digits.charAt( 0 ) ) {
				digits = digits.slice( 1 );
			}
			return digits;
		}

		formatPhoneDisplay( value ) {
			return String( value || '' );
		}

		phoneApiValue( value ) {
			return String( value || '' ).trim();
		}

		validatePhone( value ) {
			return 10 === this.phoneDigits( value ).length;
		}

		validateContactFields() {
			const fullName = String( this.state.contact.full_name || '' ).trim();
			const email = String( this.state.contact.email || '' ).trim();
			const phone = String( this.state.contact.phone || '' ).trim();
			const emailOk = '' === email || this.validateEmail( email );
			return {
				full_name: this.validateFullName( fullName ),
				email: emailOk,
				phone: this.validatePhone( phone ),
				valid: this.validateFullName( fullName ) && emailOk && this.validatePhone( phone ),
			};
		}

		isFieldInvalid( fieldName ) {
			const validation = this.validateContactFields();
			return !! ( this.state.touched && this.state.touched[ fieldName ] && false === validation[ fieldName ] );
		}

		contactFieldError( fieldName ) {
			if ( ! this.isFieldInvalid( fieldName ) ) {
				return '';
			}
			if ( 'full_name' === fieldName ) {
				return 'Enter a real full name using letters, spaces, apostrophes, hyphens, or periods.';
			}
			if ( 'phone' === fieldName ) {
				return 'Enter a valid 10-digit US phone number.';
			}
			if ( 'email' === fieldName ) {
				return 'Enter a valid email address, or leave it blank.';
			}
			return '';
		}

		contactValidationMessage() {
			const errors = config.strings.errors || {};
			const validation = this.validateContactFields();
			if ( ! validation.full_name ) {
				return errors.invalidName || 'Check this step. Enter your full name.';
			}
			if ( ! validation.email ) {
				return errors.invalidEmail || 'Check this step. Enter a valid email address, or leave email blank.';
			}
			if ( ! validation.phone ) {
				return errors.invalidPhone || 'Check this step. Enter a valid 10-digit US phone number.';
			}
			return errors.nameEmailRequired || 'Check this step. Name and phone are required before you can continue.';
		}

		taskPathChoiceMarkup( action, label, description, price, isSelected ) {
			return '<div class="handik-choice-wrap"><button data-action="' + this.escape( action ) + '" class="handik-choice handik-choice--large ' + ( isSelected ? 'is-selected' : '' ) + '"><span class="handik-choice__title">' + this.escape( label ) + '</span><span class="handik-choice__hint">' + this.escape( description ) + '</span><span class="handik-choice__price">' + this.escape( price ) + '</span></button></div>';
		}

		goTo( step ) {
			if ( 'booking' === this.state.step && 'booking' !== step ) {
				this.stopBookingStatusPolling();
			}

			const previousStep = this.state.step;
			this.state.step = step;
			this.state.footerHint = '';
			this.state.footerHintError = false;
			if ( 'task_selection' === step && previousStep !== step ) {
				this.state.selectedTasksSheetAnimate = false;
				this.state.selectedTasksSheetAttentionDismissed = false;
			}
			if ( 'address_details' === step && previousStep !== step ) {
				this.startSavedAddressLoadingIfNeeded();
			}
			if ( 'address_details' !== step ) {
				this.stopSavedAddressLoading( false );
			}
			// Issue 3 fix: when entering the assistant step without a ready bridge,
			// pre-set assistantPreparing so the rendered markup ships the overlay
			// in its visible state immediately. No DOM injection race.
			if ( 'assistant' === step && previousStep !== step && ! this.assistantBridge ) {
				this.state.assistantPreparing = true;
			}
			this.render();
			this.scrollStepIntoView();
			this.focusStepHeading();

			if ( previousStep !== step ) {
				this.pushHistoryState( step );
			}
			this.saveDraftToStorage();
		}

		scrollStepIntoView() {
			window.requestAnimationFrame( () => {
				const shell = this.root.querySelector( '.handik-booking-app__shell' );
				const header = this.root.querySelector( '.handik-booking-app__screen-header' );
				const target = header || shell || this.root;
				if ( ! target ) {
					return;
				}

				const rect = target.getBoundingClientRect();
				const absoluteTop = rect.top + window.pageYOffset;
				const top = Math.max( 0, absoluteTop - 80 );
				// Sprint 10 fix: respect prefers-reduced-motion. The CSS
				// honored it elsewhere (animations, transitions) but JS
				// scroll didn't, so reduced-motion users still got a
				// smooth scroll on every step change.
				const reduceMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
				try {
					window.scrollTo( {
						top: top,
						behavior: reduceMotion ? 'auto' : 'smooth'
					} );
				} catch ( e ) {
					window.scrollTo( 0, top );
				}
			} );
		}

		setByPath( path, value ) {
			const parts = path.split( '.' );
			let current = this.state;
			while ( parts.length > 1 ) {
				const key = parts.shift();
				current = current[ key ];
			}
			current[ parts[0] ] = value;
		}

		refreshCurrentStepActions() {
			const button = this.root.querySelector( '.handik-footer-actions .is-continue' );
			if ( ! button ) {
				return;
			}

			const muted = ! this.stepCanContinue( this.state.step );
			button.classList.toggle( 'is-pending', muted );
			button.classList.toggle( 'is-primary', ! muted );
			button.setAttribute( 'aria-disabled', muted ? 'true' : 'false' );
		}

		refreshFieldValidation( model, input ) {
			const field = input && input.closest( '.handik-field' );
			if ( ! field ) {
				return;
			}

			if ( 'contact.full_name' === model ) {
				field.classList.toggle( 'is-invalid', this.isFieldInvalid( 'full_name' ) );
				return;
			}

			if ( 'contact.email' === model ) {
				field.classList.toggle( 'is-invalid', this.isFieldInvalid( 'email' ) );
				return;
			}

			if ( 'contact.phone' === model ) {
				field.classList.toggle( 'is-invalid', this.isFieldInvalid( 'phone' ) );
				return;
			}

			if ( 'address.address_full' === model ) {
				field.classList.toggle( 'is-invalid', !! this.state.address.address_full && ! this.state.address.is_valid );
			}
		}

		taskSelected( id ) {
			return this.state.selectedTasks.includes( id );
		}

		async handleSelectedPhotos( selectedFiles, noticeTitle, noticeMessage ) {
			if ( ! selectedFiles || ! selectedFiles.length ) {
				return;
			}
			const existingCount = Array.isArray( this.state.photos ) ? this.state.photos.length : 0;
			if ( existingCount + selectedFiles.length > 8 ) {
				this.setFooterHint( 'You can upload up to 8 photos or videos for one request.', true );
				return;
			}

			// Sprint 10 fix: enforce per-file size BEFORE the XHR. Was P0 —
			// a 25 MB iPhone video on 3G uploaded fully then got rejected by
			// the server, the bytes were burned, and the file vanished from
			// the picker with only a generic toast. We mirror server limits
			// here (10 MB images, 50 MB videos) so the customer learns about
			// the size cap immediately, by name, and can pick a different file.
			const MAX_IMG = 10 * 1024 * 1024;
			const MAX_VID = 50 * 1024 * 1024;
			const tooBig = [];
			for ( const file of selectedFiles ) {
				const isVideo = ( file.type || '' ).indexOf( 'video/' ) === 0;
				const cap = isVideo ? MAX_VID : MAX_IMG;
				if ( file.size > cap ) {
					tooBig.push( {
						name: file.name || ( isVideo ? 'video' : 'image' ),
						mb: Math.round( file.size / ( 1024 * 1024 ) ),
						capMb: Math.round( cap / ( 1024 * 1024 ) ),
					} );
				}
			}
			if ( tooBig.length ) {
				const list = tooBig.map( ( f ) => f.name + ' (' + f.mb + ' MB)' ).join( ', ' );
				const cap = tooBig[ 0 ].capMb;
				this.setFooterHint(
					'Some files are too large to upload (cap is ' + cap + ' MB): ' + list +
					'. Please pick smaller files.',
					true
				);
				return;
			}

			this.state.photoUploading = true;
			this.clearFooterHint();
			this.render();

			try {
				await this.uploadFiles( selectedFiles );
				this.warmPhotoAnalysis();
			} catch ( error ) {
				this.setFooterHint( error.message, true );
			} finally {
				this.state.photoUploading = false;
				this.render();
			}
		}

		toggleTask( id ) {
			const hadItems = this.state.selectedTasks.length > 0;
			if ( this.taskSelected( id ) ) {
				this.state.selectedTasks = this.state.selectedTasks.filter( ( taskId ) => taskId !== id );
				if ( 'project_large_job' === id || 'larger_scale_work' === id ) {
					this.state.isProject = false;
				}
			} else {
				this.state.selectedTasks = this.state.selectedTasks.concat( id );
				if ( 'project_large_job' === id || 'larger_scale_work' === id ) {
					this.state.isProject = true;
				}
			}
			const hasItems = this.state.selectedTasks.length > 0;
			this.state.selectedTasksSheetAnimate = ! hadItems && hasItems;
			if ( ! hasItems ) {
				this.state.selectedTasksSheetAttentionDismissed = false;
			}
			this.state.selectedTasksSheetOpen = hasItems ? this.state.selectedTasksSheetOpen : false;
			this.state.jobShape = this.state.isProject ? 'project' : ( this.state.selectedTasks.length > 1 ? 'multiple_tasks' : 'single_task' );
			this.render();
		}

		findTask( id ) {
			const groups = ( this.bootstrap && this.bootstrap.task_catalog ) || [];
			for ( const group of groups ) {
				const found = ( group.tasks || [] ).find( ( task ) => task.id === id );
				if ( found ) {
					return found;
				}
			}
			return null;
		}

		selectedTaskItems() {
			return this.state.selectedTasks.map( ( id ) => this.findTask( id ) ).filter( Boolean );
		}

		selectedTasksSheetMarkup() {
			const items = this.selectedTaskItems();
			if ( ! items.length ) {
				return '';
			}

			const expanded = !! this.state.selectedTasksSheetOpen;
			const shouldAnimateAttention = ! expanded && ! this.state.selectedTasksSheetAttentionDismissed && ! this.state.selectedTasksSheetAnimate;
			return '<div class="handik-selected-sheet' + ( expanded ? ' is-open' : '' ) + ( this.state.selectedTasksSheetAnimate ? ' is-bouncing' : '' ) + ( shouldAnimateAttention ? ' is-attention' : '' ) + '">' +
				'<button type="button" class="handik-selected-sheet__toggle" data-action="toggle-selected-tasks" aria-expanded="' + ( expanded ? 'true' : 'false' ) + '">' +
					'<span>Selected tasks &amp; rates</span>' +
					'<span class="handik-selected-sheet__chevron" aria-hidden="true">' + ( expanded ? '&#8595;' : '&#8593;' ) + '</span>' +
				'</button>' +
				'<div class="handik-selected-sheet__body">' +
					items.map( ( task ) => '<article class="handik-selected-sheet__item"><div class="handik-selected-sheet__item-head"><strong>' + this.escape( task.label || 'Task' ) + '</strong>' + ( task.rate_label ? '<span class="handik-selected-sheet__rate">' + this.escape( task.rate_label ) + '</span>' : '' ) + '</div><p>' + this.escape( task.description || '' ) + '</p></article>' ).join( '' ) +
				'</div>' +
			'</div>';
		}

		async handleAction( action, button ) {
			this.clearFooterHint();
			switch ( action ) {
				case 'start':
					this.goTo( 'task_selection' );
					break;
				case 'choose-general-handyman':
					this.state.selectedTasks = [ 'general_handyman_help' ];
					this.state.taskSelectionMode = 'overview';
					this.state.selectedTasksSheetOpen = false;
					this.state.selectedTasksSheetAnimate = false;
					this.state.selectedTasksSheetAttentionDismissed = true;
					this.state.isProject = false;
					this.state.jobShape = 'single_task';
					this.goTo( 'photos' );
					break;
				case 'choose-larger-scale':
					this.state.selectedTasks = [ 'larger_scale_work' ];
					this.state.taskSelectionMode = 'overview';
					this.state.selectedTasksSheetOpen = false;
					this.state.selectedTasksSheetAnimate = false;
					this.state.selectedTasksSheetAttentionDismissed = true;
					this.state.isProject = true;
					this.state.jobShape = 'project';
					this.goTo( 'photos' );
					break;
				case 'choose-specific-tasks':
					this.state.selectedTasks = [];
					this.state.taskSelectionMode = 'specific';
					this.state.selectedTasksSheetOpen = false;
					this.state.selectedTasksSheetAnimate = false;
					this.state.selectedTasksSheetAttentionDismissed = false;
					this.state.isProject = false;
					this.state.jobShape = '';
					this.render();
					this.scrollStepIntoView();
					break;
				case 'tasks-next':
					if ( ! this.stepCanContinue( 'task_selection' ) ) {
						this.showStepWarning( 'task_selection' );
						return;
					}
					this.state.jobShape = this.state.isProject ? 'project' : ( this.state.selectedTasks.length > 1 ? 'multiple_tasks' : 'single_task' );
					this.goTo( 'photos' );
					break;
				case 'address-next':
					if ( ! this.stepCanContinue( 'address_details' ) ) {
						this.showStepWarning( 'address_details' );
						return;
					}
					if ( ! this.isServiceableZip( this.state.address.zip_code ) ) {
						// Sprint 10 fix: was a transient footer-hint toast that
						// auto-dismissed in 4.2s — owner-reported P0. Now we
						// flip a persistent flag on the address state so the
						// inline error stays visible until the customer picks
						// a different address. The flag clears in
						// applyAddressFromPlaces() and in onInput for
						// address.address_full so editing the address is
						// frictionless.
						this.state.address.zipBlocked = String( this.state.address.zip_code || '' );
						this.render();
						return;
					}
					await this.saveContactAndPrepareBooking();
					break;
				case 'photos-next':
					// Sprint 9 fix: skip the contact + OTP steps entirely
					// when the verified-client cache has already restored
					// the profile on init. Mirrors the behaviour after a
					// fresh OTP (`verifyPhoneOtp` → goTo('address_details')).
					if ( this.state.phoneVerified ) {
						this.goTo( 'address_details' );
					} else {
						this.goTo( 'contact_details' );
					}
					break;
				case 'contact-next':
					// Sprint 6: contact_details is now phone-only. Continue
					// fires Twilio Verify and routes to the OTP screen.
					if ( ! this.stepCanContinue( 'contact_details' ) ) {
						this.state.touched.phone = true;
						this.render();
						this.setFooterHint( this.contactValidationMessage(), true );
						return;
					}
					await this.startPhoneOtp();
					break;
				case 'otp-verify':
					if ( ! this.stepCanContinue( 'otp_verify' ) ) { return; }
					await this.verifyPhoneOtp();
					break;
				case 'otp-resend':
					if ( Date.now() < this.state.otpResendDisabledUntil ) { return; }
					await this.startPhoneOtp( /* isResend */ true );
					break;
				case 'otp-back':
					this.state.otpCode = '';
					this.state.otpError = '';
					this.state.otpErrorKind = '';
					this.goTo( 'contact_details' );
					break;
				case 'open-booking':
					if ( this.state.bookingUrl ) {
						window.open( this.state.bookingUrl, '_blank', 'noopener,noreferrer' );
						this.state.bookingOpened = true;
						this.setBookingStatusMessage( config.strings.bookingWaiting || 'Hang tight - confirming your booking.', false );
					}
					break;
				case 'booking-complete':
				case 'refresh-booking-status':
					await this.completeBookingStep();
					break;
				case 'assistant-next':
					await this.completeAssistantStep();
					break;
				case 'choose-photos':
					{
						const photoInput = this.root.querySelector( '#handik-photo-input' );
						if ( photoInput ) {
							photoInput.click();
						}
					}
					break;
				case 'remove-photo':
					{
						const photoId = button ? button.getAttribute( 'data-photo-id' ) : '';
						if ( ! photoId ) {
							break;
						}
						this.state.photos = ( this.state.photos || [] ).filter( ( photo, index ) => {
							const candidateId = String( photo.id || photo.attachment_id || photo.url || ( 'photo-' + index ) );
							return candidateId !== String( photoId );
						} );
						this.render();
						this.saveDraftToStorage();
					}
					break;
				case 'restart':
					this.requestRestart();
					break;
				case 'restart-cancel':
					this.state.restartConfirmVisible = false;
					this.render();
					break;
				case 'restart-confirm':
					this.executeRestart();
					break;
				case 'back-from-unsafe':
					this.state.unsafeReason = '';
					this.goTo( 'assistant' );
					break;
				case 'restart-now':
					this.executeRestart();
					break;
				case 'noop':
					break;
				case 'back-start':
					this.setFooterHint( '', false );
					break;
				case 'back-tasks':
					if ( 'specific' === this.state.taskSelectionMode ) {
						this.state.taskSelectionMode = 'overview';
						this.render();
						this.scrollStepIntoView();
					} else {
						this.goTo( 'task_selection' );
					}
					break;
				case 'back-address':
					// Sprint 10 fix: for verified customers, contact_details
					// + otp_verify are no longer in `applicableSteps()` —
					// jumping to them would land on a step the progress
					// bar doesn't show, and the contact step would re-ask
					// for the phone they already verified, silently
					// invalidating their cached profile. Back from address
					// now goes to `photos` for verified customers (the
					// previous step in their abridged timeline) and stays
					// at `otp_verify` for unverified customers.
					this.goTo( this.state.phoneVerified ? 'photos' : 'otp_verify' );
					break;
				case 'back-photos':
					this.goTo( 'task_selection' );
					break;
				case 'back-contact':
					this.goTo( 'photos' );
					break;
				case 'back-assistant':
					this.goTo( 'address_details' );
					break;
				case 'back-booking':
					this.goTo( 'assistant' );
					break;
				case 'choose-task-option':
					this.showStepWarning( 'task_selection', 'Choose one of the options above to continue.' );
					break;
				case 'choose-time-placeholder':
					this.notify( 'info', '', 'Choose a time in the calendar above.', 4200 );
					break;
				case 'toggle-project':
					break;
				case 'toggle-selected-tasks':
					this.state.selectedTasksSheetOpen = ! this.state.selectedTasksSheetOpen;
					if ( this.state.selectedTasksSheetOpen ) {
						this.state.selectedTasksSheetAttentionDismissed = true;
					}
					this.render();
					break;
				case 'retry-assistant':
					if ( window.HandikChatKitBridge && typeof window.HandikChatKitBridge.reset === 'function' && this.state.requestId ) {
						window.HandikChatKitBridge.reset( 'request_' + String( this.state.requestId ) );
					}
					this.mountAssistant();
					break;
			}
		}

		requestRestart() {
			this.state.restartConfirmVisible = true;
			this.render();
		}

		executeRestart( opts ) {
			this.stopBookingStatusPolling();
			this.clearAssistantStatusBlock();
			// Sprint 10 fix: also clear the assistant "we got stuck" banner
			// — if the customer recovered via Restart, the banner used to
			// stay glued to the host element across the new session.
			this.clearAssistantStuckBanner();
			this.stopSavedAddressLoading( false );
			this.savedAddressLoadingProfileKey = '';
			this.assistantSessionPrewarmPromise = null;
			this.assistantSessionPrewarmedRequestId = 0;
			this.assistantPrewarmedSession = null;
			this.assistantPrewarmedRequestId = 0;
					this.clearDraftStorage();
					// Sprint 10 fix: Restart no longer wipes the 30-day
					// verified-client cache. Owner-reported P0 — a customer
					// who tapped Restart mid-OTP lost the cached identity
					// and had to OTP again on the next booking, defeating
					// the "no second OTP for 30 days" promise. The cache is
					// now only cleared by an explicit "Sign out" action
					// (handled in `handleAction` 'sign-out'). Note: the
					// state-reset block below preserves verifiedToken /
					// verifiedPhone / verifiedProfile / phoneVerified so
					// the next session's init() doesn't even need to
					// re-fetch /phone-verify/restore.
					const preserveIdentity = ! ( opts && opts.signOut );
					if ( ! preserveIdentity ) {
						clearVerifiedClient();
					}
					if ( window.HandikChatKitBridge && typeof window.HandikChatKitBridge.reset === 'function' && this.state.requestId ) {
						window.HandikChatKitBridge.reset( 'request_' + String( this.state.requestId ) );
					}
					this.photoAnalysisWarmRequestId = 0;
					this.photoAnalysisWarmPromise = null;
					this.photoAnalysisWarmPromiseRequestId = 0;
					this.assistantContextDispatchPromise = null;
					if ( this.assistantUnlockTimer ) {
						window.clearTimeout( this.assistantUnlockTimer );
						this.assistantUnlockTimer = null;
					}
					this.assistantMountPromise = null;
					this.pendingAssistantContextAnalysis = null;
					// Sprint 10 fix: identity / verification fields are
					// preserved across Restart (unless this is an explicit
					// Sign-Out invocation, see `preserveIdentity` above).
					// The customer keeps the 30-day "no second OTP" path
					// and the contact prefill on the next booking; only
					// the in-flight draft is wiped.
					const restartReset = {
						step: 'task_selection',
						lastLookupPhone: '',
						requestId: 0,
						draftToken: '',
						selectedTasks: [],
						taskSelectionMode: 'overview',
						selectedTasksSheetOpen: false,
						selectedTasksSheetAnimate: false,
						selectedTasksSheetAttentionDismissed: false,
						isProject: false,
						jobShape: '',
						address: { address_id: 0, address_full: '', address_line_1: '', address_unit: '', city: '', state: '', zip_code: '', is_valid: false, zipBlocked: '' },
						photos: [],
						shortDescription: '',
						assistantResult: null,
						assistantResultSaved: false,
						assistantBookingUrlReady: false,
						assistantReadyForBooking: false,
						assistantRoutingPending: false,
						assistantAwaitingResponse: false,
						assistantResponseSeen: false,
						assistantFallbackMessage: '',
						assistantUserMessageSent: false,
						assistantThreadId: '',
						touched: { full_name: false, email: false, phone: false },
						bookingUrl: '',
						bookingUrlLocked: false,
						bookingStatus: '',
						bookingStatusMessage: '',
						unsafeReason: '',
						appSessionKey: 'app_' + Math.random().toString( 36 ).slice( 2 ),
						message: '',
						lastAssistantNotice: '',
						assistantPreparing: false,
						footerHint: '',
						footerHintError: false,
						restartConfirmVisible: false,
						savedAddressLoading: false,
						photoUploading: false,
						bookingOpened: false
					};
					// Only wipe identity on explicit Sign-Out.
					if ( ! preserveIdentity ) {
						restartReset.isReturningClient = false;
						restartReset.verifiedProfile = null;
						restartReset.verifiedToken = '';
						restartReset.verifiedPhone = '';
						restartReset.phoneVerified = false;
						restartReset.contact = { first_name: '', last_name: '', full_name: '', email: '', phone: '' };
					} else {
						// Keep the contact prefill so the customer doesn't
						// have to re-type their name on the new request.
						restartReset.contact = Object.assign(
							{ first_name: '', last_name: '', full_name: '', email: '', phone: '' },
							this.state.contact || {}
						);
					}
					this.state = Object.assign( this.state, restartReset );
			this.goTo( 'task_selection' );
		}

		prefillFromProfile() {
			if ( ! this.state.verifiedProfile || ! this.state.verifiedProfile.contact ) {
				return;
			}
			const contact = this.state.verifiedProfile.contact;
			if ( ! String( this.state.contact.full_name || '' ).trim() && contact.full_name ) {
				this.state.contact.full_name = contact.full_name;
			}
			if ( ! String( this.state.contact.email || '' ).trim() && contact.email ) {
				this.state.contact.email = contact.email;
			}
			if ( ! String( this.state.contact.phone || '' ).trim() && contact.phone ) {
				this.state.contact.phone = String( contact.phone );
			}
		}

		// Build a human-readable / submittable address string for a saved
		// address row. Saved rows are allowed to have an empty `address_full`
		// as long as `address_line_1` is set (see Addresses_Service::create),
		// so we fall back to the structured parts. This keeps the dropdown
		// labels readable AND keeps state.address.address_full non-empty —
		// otherwise stepCanContinue('address_details') stays false and the
		// Continue button never un-mutes. Mirrors the Additional Forms flow.
		composeSavedAddress( item ) {
			const full = String( ( item && item.address_full ) || '' ).trim();
			if ( full ) {
				return full;
			}
			const parts = [
				( item && item.address_line_1 ) || '',
				( item && item.city ) || '',
				( item && item.state ) || '',
				( item && item.zip_code ) || ''
			].map( ( part ) => String( part || '' ).trim() ).filter( Boolean );
			return parts.join( ', ' );
		}

		isServiceableZip( zip ) {
			const list = Array.isArray( config.serviceableZips ) ? config.serviceableZips : [];
			if ( ! list.length ) {
				return true;
			}
			return list.includes( String( zip || '' ).trim() );
		}

		savedAddressProfileKey() {
			if ( ! this.state.verifiedProfile || ! this.state.verifiedProfile.contact ) {
				return '';
			}
			const contact = this.state.verifiedProfile.contact;
			return String( contact.id || contact.phone || contact.email || contact.full_name || '' );
		}

		startSavedAddressLoadingIfNeeded() {
			const key = this.savedAddressProfileKey();
			if ( ! key ) {
				this.stopSavedAddressLoading( false );
				this.savedAddressLoadingProfileKey = '';
				return;
			}
			if ( key === this.savedAddressLoadingProfileKey && ! this.state.savedAddressLoading ) {
				return;
			}
			if ( key === this.savedAddressLoadingProfileKey && this.state.savedAddressLoading ) {
				return;
			}

			this.savedAddressLoadingProfileKey = key;
			this.state.savedAddressLoading = true;
			if ( this.savedAddressLoadingTimer ) {
				window.clearTimeout( this.savedAddressLoadingTimer );
			}
			this.savedAddressLoadingTimer = window.setTimeout( () => {
				this.stopSavedAddressLoading( true );
			}, 1100 );
		}

		stopSavedAddressLoading( shouldRender ) {
			if ( this.savedAddressLoadingTimer ) {
				window.clearTimeout( this.savedAddressLoadingTimer );
				this.savedAddressLoadingTimer = null;
			}
			if ( this.state.savedAddressLoading ) {
				this.state.savedAddressLoading = false;
				if ( shouldRender && 'address_details' === this.state.step ) {
					this.render();
				}
			}
		}

		// =====================================================================
		// Sprint 6 — phone-first OTP flow.
		// =====================================================================

		async startPhoneOtp( isResend ) {
			const phoneRaw = String( this.state.contact.phone || '' );
			if ( ! this.validatePhone( phoneRaw ) ) {
				this.notify( 'warning', '', config.strings.errors && config.strings.errors.invalidPhone || 'Please enter a valid phone number.', 4200 );
				return;
			}
			const phoneApi = this.phoneApiValue ? this.phoneApiValue( phoneRaw ) : phoneRaw;
			this.state.loading = true;
			this.render();
			try {
				const res = await this.api( 'phone-verify/start', { phone: phoneApi }, 'POST' );
				this.state.otpError = '';
				this.state.otpResendDisabledUntil = Date.now() + 30 * 1000;
				if ( ! isResend ) {
					this.goTo( 'otp_verify' );
				}
				this.notify( 'info', '', ( res && res.message ) || ( config.strings.otpSentToast || 'Code sent.' ), 3200 );
			} catch ( error ) {
				this.notify( 'error', '', error.message || 'Could not send code.', 5000 );
			} finally {
				this.state.loading = false;
				this.render();
			}
		}

		async verifyPhoneOtp() {
			// Hotfix 2.1.13.1: re-entry guard. Auto-advance fires this on the
			// keystroke that completes the 6th digit; iOS autofill / paste
			// can also trigger a duplicate input event in the same tick.
			// Without the guard we'd double-POST /phone-verify/check —
			// Twilio invalidates the verification on the first hit, so the
			// second hit returns 404 ("VerificationCheck not found"). That's
			// the production error the owner reported.
			if ( this._otpVerifyInFlight ) { return; }
			this._otpVerifyInFlight = true;
			const phoneRaw = String( this.state.contact.phone || '' );
			const phoneApi = this.phoneApiValue ? this.phoneApiValue( phoneRaw ) : phoneRaw;
			const code = String( this.state.otpCode || '' ).replace( /\D/g, '' );
			this.state.loading = true;
			this.render();
			try {
				const res = await this.api( 'phone-verify/check', { phone: phoneApi, code: code }, 'POST' );
				this.state.otpError = '';
				this.state.verifiedPhone = String( res.verified_phone || phoneApi );
				this.state.verifiedToken = String( res.verified_token || '' );
				this.state.phoneVerified = true;
				this.state.isReturningClient = ! res.is_new_client;
				if ( res.profile && res.profile.contact ) {
					// Re-use the existing verifiedProfile slot so the rest of
					// the SPA (saved-address dropdown, prefillFromProfile)
					// keeps working unchanged.
					this.state.verifiedProfile = res.profile;
					this.prefillFromProfile();
				}
				saveVerifiedClient( {
					token: this.state.verifiedToken,
					phone: this.state.verifiedPhone,
					savedAt: Date.now()
				} );
				if ( this.state.isReturningClient ) {
					this.notify( 'info', '', config.strings.welcomeBack || 'Welcome back — we found your saved details.', 3600 );
				}
				this.goTo( 'address_details' );
			} catch ( error ) {
				// Sprint 10 fix: distinguish "wrong code" from "rate-limited
				// — try again in a few minutes". Auth_Service returns the
				// `rate_limited` error code for both /start and /check
				// after too many attempts; this code surfaces in
				// `error.code` via the api() helper. UI flag triggers a
				// different copy + amber styling so the customer doesn't
				// keep mashing the keypad against a locked-out backend.
				const code = String( ( error && error.code ) || '' );
				const msg  = String( ( error && error.message ) || '' );
				const isRateLimit =
					'rate_limited' === code
					|| /too many|rate.?limit/i.test( msg );
				if ( isRateLimit ) {
					this.state.otpError = msg || 'Too many verification attempts. Try again in a few minutes.';
					this.state.otpErrorKind = 'rate_limit';
				} else {
					this.state.otpError = msg || ( config.strings.otpInvalid || 'That code is invalid or expired.' );
					this.state.otpErrorKind = 'invalid';
				}
				// Wipe the buffer so the customer can retype without manually
				// clearing the field. Auto-advance re-fires on the next 6
				// fresh digits.
				this.state.otpCode = '';
				this.render();
			} finally {
				this._otpVerifyInFlight = false;
				this.state.loading = false;
				this.render();
			}
		}

		async tryRestoreVerifiedClient() {
			const stored = readVerifiedClient();
			if ( ! stored || ! stored.token ) { return false; }
			try {
				const res = await this.api( 'phone-verify/restore', { verified_token: stored.token }, 'POST' );
				this.state.verifiedToken = stored.token;
				this.state.verifiedPhone = String( res.verified_phone || stored.phone || '' );
				this.state.phoneVerified = true;
				this.state.isReturningClient = ! res.is_new_client;
				if ( this.state.verifiedPhone && ! this.state.contact.phone ) {
					this.state.contact.phone = this.state.verifiedPhone;
				}
				if ( res.profile && res.profile.contact ) {
					this.state.verifiedProfile = res.profile;
					this.prefillFromProfile();
				}
				// Sprint 10 fix: surface the recognition. Was P1 — the
				// restore flow worked end-to-end (profile prefilled, OTP
				// skipped) but the customer had no idea why their saved
				// addresses were already populated. Toast deferred until
				// init() finishes so it doesn't fight the "Loading…"
				// overlay; gated on `isReturningClient` so brand-new
				// users (cache present from a prior new-client session
				// that didn't book) don't get a misleading "welcome back".
				if ( this.state.isReturningClient ) {
					this._pendingWelcomeBackToast = true;
				}
				return true;
			} catch ( e ) {
				clearVerifiedClient();
				return false;
			}
		}

		async maybeLookupContact( immediate ) {
			const phone = String( this.state.contact.phone || '' ).trim();
			if ( ! this.validatePhone( phone ) ) {
				return false;
			}
			const lookupPhone = this.phoneDigits( phone );
			if ( ! immediate && this._contactLookupTimer ) {
				window.clearTimeout( this._contactLookupTimer );
			}
			if ( ! immediate ) {
				this._contactLookupTimer = window.setTimeout( () => this.maybeLookupContact( true ), 500 );
				return false;
			}
			if ( this.state.lastLookupPhone === lookupPhone || this._contactLookupInFlight === lookupPhone ) {
				return false;
			}

			this.state.lastLookupPhone = lookupPhone;
			this._contactLookupInFlight = lookupPhone;
			try {
				const result = await this.api( 'contacts/lookup', {
					phone: this.phoneApiValue( phone )
				} );
				if ( result && result.profile && result.profile.contact ) {
					this.state.verifiedProfile = result.profile;
					this.state.isReturningClient = true;
					this.prefillFromProfile();
					this.notify( 'info', '', 'Welcome back — we found your details.', 4000 );
					this.render();
					this.saveDraftToStorage();
					return true;
				}
				this.state.verifiedProfile = null;
				this.state.isReturningClient = false;
				return false;
			} catch ( error ) {
				this.logClient( 'warn', 'Contact lookup failed.', {
					error: error && error.message ? error.message : 'unknown'
				} );
				return false;
			} finally {
				this._contactLookupInFlight = '';
			}
		}

		async uploadFiles( files ) {
			if ( ! files || ! files.length ) {
				return;
			}
			await this.ensureDraftRequest( 'photos' );
			const preparedFiles = [];
			for ( const file of files ) {
				preparedFiles.push( await this.prepareUploadFile( file ) );
			}
			const uploads = await Promise.all( preparedFiles.map( ( file ) => {
				const formData = new window.FormData();
				formData.append( 'file', file );
				formData.append( 'request_id', String( this.state.requestId || 0 ) );
				formData.append( 'draft_token', this.state.draftToken || '' );
				formData.append( 'contact_id', String( ( this.state.verifiedProfile && this.state.verifiedProfile.contact && this.state.verifiedProfile.contact.id ) || 0 ) );
				formData.append( 'app_session_key', this.state.appSessionKey );
				return this.api( 'app/upload', {}, 'POST', formData );
			} ) );
			this.state.photos = this.state.photos.concat( uploads );
			this.photoAnalysisWarmRequestId = 0;
			this.photoAnalysisWarmPromise = null;
			this.photoAnalysisWarmPromiseRequestId = 0;
			this.assistantContextDispatchPromise = null;
			this.pendingAssistantContextAnalysis = null;
		}

		async prepareUploadFile( file ) {
			if ( ! file || ! file.type || 0 !== file.type.indexOf( 'image/' ) || file.size < 1400000 || ! window.HTMLCanvasElement ) {
				return file;
			}

			try {
				const bitmap = await window.createImageBitmap( file );
				const maxDimension = 1600;
				const scale = Math.min( 1, maxDimension / Math.max( bitmap.width, bitmap.height ) );
				const width = Math.max( 1, Math.round( bitmap.width * scale ) );
				const height = Math.max( 1, Math.round( bitmap.height * scale ) );
				const canvas = document.createElement( 'canvas' );
				canvas.width = width;
				canvas.height = height;
				const context = canvas.getContext( '2d' );
				if ( ! context ) {
					return file;
				}

				context.drawImage( bitmap, 0, 0, width, height );
				const mimeType = /png|webp/i.test( file.type ) ? 'image/jpeg' : file.type;
				const blob = await new Promise( ( resolve ) => canvas.toBlob( resolve, mimeType, 0.82 ) );
				if ( ! blob || blob.size >= file.size ) {
					return file;
				}

				const extension = 'image/png' === mimeType ? '.png' : '.jpg';
				const baseName = String( file.name || 'photo' ).replace( /\.[^.]+$/, '' );
				return new window.File( [ blob ], baseName + extension, { type: mimeType, lastModified: Date.now() } );
			} catch ( error ) {
				return file;
			}
		}

		async warmPhotoAnalysis() {
			if ( ! this.state.requestId || ! this.state.draftToken || ! this.state.photos.length ) {
				return;
			}

			if ( this.pendingAssistantContextAnalysis && this.photoAnalysisWarmRequestId === this.state.requestId ) {
				return this.pendingAssistantContextAnalysis;
			}

			if ( this.photoAnalysisWarmPromise && this.photoAnalysisWarmPromiseRequestId === this.state.requestId ) {
				return this.photoAnalysisWarmPromise;
			}

			this.photoAnalysisWarmPromiseRequestId = this.state.requestId;
			this.photoAnalysisWarmPromise = ( async() => {
				try {
					await this.logClient( 'info', 'Photo analysis warmup started.', {
						request_id: this.state.requestId,
						photo_count: this.state.photos.length
					} );
					const response = await this.withTimeout( this.api( 'photo-analysis', {
						request_id: this.state.requestId,
						draft_token: this.state.draftToken
					} ), 30000, 'Photo analysis warmup' );
					this.photoAnalysisWarmRequestId = this.state.requestId;
					this.pendingAssistantContextAnalysis = response ? response.photo_analysis || null : null;
					await this.logClient( 'info', 'Photo analysis warmup completed.', {
						request_id: this.state.requestId,
						photo_count: this.state.photos.length
					} );
					return this.pendingAssistantContextAnalysis;
				} catch ( error ) {
					this.logClient( 'debug', 'Photo analysis warmup failed.', {
						request_id: this.state.requestId,
						error: error && error.message ? error.message : 'unknown'
					} );
					return null;
				} finally {
					this.photoAnalysisWarmPromise = null;
					this.photoAnalysisWarmPromiseRequestId = 0;
				}
			} )();

			return this.photoAnalysisWarmPromise;
		}

		async unlockAssistantAfterPhotoContext( session ) {
			if ( this.assistantContextDispatchPromise ) {
				return this.assistantContextDispatchPromise;
			}

			this.assistantContextDispatchPromise = ( async() => {
				const analysis = this.pendingAssistantContextAnalysis || ( session && session.draft_context && session.draft_context.photo_analysis ? session.draft_context.photo_analysis : null );
				const signature = analysis && analysis.photos_signature ? String( analysis.photos_signature ) : '';

				try {
					await this.logClient( 'info', 'Assistant photo gate started.', {
						request_id: this.state.requestId,
						has_analysis: !! analysis,
						tool_context_available: !! ( Array.isArray( this.state.photos ) && this.state.photos.length )
					} );

					if ( analysis ) {
						await this.logClient( 'info', 'Assistant photo analysis ready.', {
							request_id: this.state.requestId,
							photos_signature: signature,
							actionable: !! analysis.has_actionable_visual_context
						} );
					}
				} finally {
					this.setAssistantPreparingState( false );
					await this.logClient( 'info', 'Assistant composer unlocked for tool-based photo context.', {
						request_id: this.state.requestId,
						photos_signature: signature || ''
					} );
				}
			} )().finally( () => {
				this.assistantContextDispatchPromise = null;
			} );

			return this.assistantContextDispatchPromise;
		}

		async prepareAssistantStep() {
			if ( 'assistant' !== this.state.step || ! this.state.requestId || ! this.state.draftToken ) {
				return;
			}

			if ( this.assistantPreparationPromise ) {
				return this.assistantPreparationPromise;
			}

			this.assistantPreparationPromise = ( async() => {
				if ( 'assistant' === this.state.step ) {
					this.mountAssistant();
				}

				if ( Array.isArray( this.state.photos ) && this.state.photos.length ) {
					this.warmPhotoAnalysis();
				}
			} )().finally( () => {
				this.assistantPreparationPromise = null;
			} );

			this.setAssistantPreparingState( ! this.assistantBridge );
			// Hotfix: hard-stop the loading state after 14s no matter what the
			// ChatKit bridge reports. Even when the element is silently stuck,
			// the user is unblocked: they can either wait + retry or use Plan B.
			this.startAssistantPreparingSafetyTimer();

			return this.assistantPreparationPromise;
		}

		startAssistantPreparingSafetyTimer() {
			this.stopAssistantPreparingSafetyTimer();
			this.assistantPreparingSafetyTimer = window.setTimeout( () => {
				this.assistantPreparingSafetyTimer = null;
				if ( ! this.state.assistantPreparing ) {
					return;
				}
				// Force-dismiss the overlay so the user can interact (or fall
				// back to the booking link). We do this even if the bridge
				// hasn't reported ready — better the user sees something than
				// stares at a spinner.
				this.logClient( 'warn', 'Assistant preparing safety timer expired, force-dismissing overlay.', {
					request_id: this.state.requestId,
					has_bridge: !! this.assistantBridge
				} );
				this.setAssistantPreparingState( false );
				if ( ! this.assistantBridge ) {
					this.showAssistantStuckBanner( 'preparing-timeout' );
				}
			}, 14000 );
		}

		stopAssistantPreparingSafetyTimer() {
			if ( this.assistantPreparingSafetyTimer ) {
				window.clearTimeout( this.assistantPreparingSafetyTimer );
				this.assistantPreparingSafetyTimer = null;
			}
		}

		prewarmAssistantSession() {
			if ( 'address_details' !== this.state.step || ! this.state.requestId || ! this.state.draftToken ) {
				return;
			}
			const requestId = Number( this.state.requestId ) || 0;
			if ( this.assistantBridge || this.assistantSessionPrewarmPromise || this.assistantSessionPrewarmedRequestId === requestId ) {
				return;
			}
			this.assistantSessionPrewarmedRequestId = requestId;

			this.assistantSessionPrewarmPromise = this.api( 'chatkit-session', {
				request_id: this.state.requestId,
				draft_token: this.state.draftToken
			} ).then( ( payload ) => {
				// Sprint 1 A3: stash the payload so mountAssistant can hand it
				// straight to the bridge — saves a full create-session round-trip
				// on the very first getClientSecret() call.
				if ( payload && ( payload.client_secret || payload.clientSecret ) ) {
					this.assistantPrewarmedSession = payload;
					this.assistantPrewarmedRequestId = requestId;
				}
				this.logClient( 'info', 'ChatKit session prewarmed before assistant step.', {
					request_id: this.state.requestId,
					has_client_secret: !! ( payload && ( payload.client_secret || payload.clientSecret ) )
				} );
				return payload;
			} ).catch( ( error ) => {
				this.logClient( 'debug', 'ChatKit session prewarm skipped.', {
					request_id: this.state.requestId,
					error: error.message || 'Unknown error'
				} );
			} ).finally( () => {
				this.assistantSessionPrewarmPromise = null;
			} );
		}

		// 2.1.29.0 — Single assistant status block.
		//
		// Owner-reported: assistant first-token latency is 20-60s on real
		// traffic and the old "Thinking…" pill felt static / easy to miss.
		// This system shows ONE prominent block immediately after the user
		// presses Send and rotates its copy on a timeline (1s, 5s, 10s, 20s,
		// 30s, 40s, 50s — see ASSISTANT_STATUS_STAGES). At 50s the block
		// adds a soft "Open the booking page directly" link so the customer
		// is never trapped, but the wait itself is presented as in-progress,
		// not broken.
		//
		// The block is DOM-only: nothing here calls the API, records to
		// handik_messages, or feeds back into the ChatKit thread.

		startAssistantStatusBlock() {
			const host = this.root.querySelector( '.handik-booking-app__assistant-host' );
			if ( ! host ) {
				return;
			}
			// Cancel any in-flight timers from the previous turn so this
			// turn restarts cleanly from stage 0.
			this.clearAssistantStatusTimers();
			this.assistantStatusActive = true;

			let block = host.querySelector( '.handik-assistant-status' );
			if ( ! block ) {
				block = document.createElement( 'aside' );
				block.className = 'handik-assistant-status';
				block.setAttribute( 'role', 'status' );
				block.setAttribute( 'aria-live', 'polite' );
				block.innerHTML =
					'<span class="handik-assistant-status__dots" aria-hidden="true"><span></span><span></span><span></span></span>' +
					'<div class="handik-assistant-status__content">' +
						'<p class="handik-assistant-status__text"></p>' +
						'<a class="handik-assistant-status__link" target="_blank" rel="noopener" hidden></a>' +
					'</div>';
				host.appendChild( block );
			} else {
				// Reset visual state on reuse so a fast second turn doesn't
				// re-show the 50s "stuck" link from the previous turn.
				block.classList.remove( 'has-link' );
				const staleLink = block.querySelector( '.handik-assistant-status__link' );
				if ( staleLink ) {
					staleLink.hidden = true;
					staleLink.removeAttribute( 'href' );
				}
			}

			window.requestAnimationFrame( () => {
				if ( ! this.assistantStatusActive ) {
					return;
				}
				block.classList.add( 'is-visible' );
			} );

			ASSISTANT_STATUS_STAGES.forEach( ( stage, index ) => {
				const timer = window.setTimeout( () => {
					this.setAssistantStatusStage( index );
				}, stage.delay );
				this.assistantStatusTimers.push( timer );
			} );
		}

		setAssistantStatusStage( index ) {
			const host = this.root.querySelector( '.handik-booking-app__assistant-host' );
			const block = host ? host.querySelector( '.handik-assistant-status' ) : null;
			if ( ! block || ! this.assistantStatusActive ) {
				return;
			}
			const stage = ASSISTANT_STATUS_STAGES[ index ];
			if ( ! stage ) {
				return;
			}
			const text = block.querySelector( '.handik-assistant-status__text' );
			const link = block.querySelector( '.handik-assistant-status__link' );
			if ( text ) {
				text.textContent = stage.text;
			}
			block.setAttribute( 'data-handik-status-stage', String( index ) );
			if ( stage.showLink && link ) {
				const url = this.directBookingUrl();
				if ( url ) {
					link.href = url;
					link.textContent = 'Open the booking page directly';
					link.hidden = false;
					block.classList.add( 'has-link' );
				}
			}
		}

		clearAssistantStatusBlock() {
			this.assistantStatusActive = false;
			this.clearAssistantStatusTimers();
			const host = this.root.querySelector( '.handik-booking-app__assistant-host' );
			const block = host ? host.querySelector( '.handik-assistant-status' ) : null;
			if ( ! block ) {
				return;
			}
			block.classList.remove( 'is-visible' );
			// Defer removal so the CSS fade-out runs and the block doesn't
			// flash off-screen between two fast turns of the conversation.
			window.setTimeout( () => {
				if ( ! this.assistantStatusActive && block.parentNode ) {
					block.parentNode.removeChild( block );
				}
			}, 220 );
		}

		clearAssistantStatusTimers() {
			if ( Array.isArray( this.assistantStatusTimers ) ) {
				this.assistantStatusTimers.forEach( ( id ) => window.clearTimeout( id ) );
			}
			this.assistantStatusTimers = [];
		}

		showAssistantStuckBanner( reason ) {
			const host = this.root.querySelector( '.handik-booking-app__assistant-host' );
			if ( ! host ) {
				return;
			}
			if ( host.querySelector( '.handik-assistant-stuck-banner' ) ) {
				return; // already shown
			}
			const fallbackUrl = this.directBookingUrl();
			const banner = document.createElement( 'div' );
			banner.className = 'handik-assistant-stuck-banner';
			banner.setAttribute( 'role', 'alert' );
			banner.setAttribute( 'data-handik-stuck-reason', String( reason || 'unknown' ) );
			const title = config.strings.assistantStuckTitle || 'The assistant is taking longer than usual';
			const body = config.strings.assistantStuckBody || 'You can keep waiting, or open the booking page directly and Alex will sort out the details on site.';
			const cta = config.strings.assistantStuckCta || 'Open the booking page directly →';
			banner.innerHTML = '<strong>' + this.escape( title ) + '</strong>' +
				'<p>' + this.escape( body ) + '</p>' +
				( fallbackUrl ? '<a class="handik-btn is-primary" target="_blank" rel="noopener" href="' + this.escape( fallbackUrl ) + '" data-handik-stuck-cta>' + this.escape( cta ) + '</a>' : '' );
			host.appendChild( banner );
			window.requestAnimationFrame( () => banner.classList.add( 'is-visible' ) );
			this.logClient( 'warn', 'Assistant stuck banner shown.', {
				request_id: this.state.requestId,
				reason: String( reason || 'unknown' ),
				has_fallback_url: !! fallbackUrl
			} );
			// Track CTA clicks so the admin sees how often Plan B is used.
			const cta_link = banner.querySelector( '[data-handik-stuck-cta]' );
			if ( cta_link ) {
				cta_link.addEventListener( 'click', () => {
					this.logClient( 'warn', 'Assistant stuck Plan B clicked.', {
						request_id: this.state.requestId,
						reason: String( reason || 'unknown' )
					} );
				}, { once: true } );
			}
		}

		clearAssistantStuckBanner() {
			const host = this.root.querySelector( '.handik-booking-app__assistant-host' );
			if ( ! host ) {
				return;
			}
			const banner = host.querySelector( '.handik-assistant-stuck-banner' );
			if ( banner ) {
				banner.remove();
			}
		}

		async ensureDraftRequest( appStep ) {
			if ( this.state.requestId && this.state.draftToken ) {
				return {
					request_id: this.state.requestId,
					draft_token: this.state.draftToken
				};
			}

			const response = await this.api( 'app/draft', this.buildDraftPayload( appStep || 'photos' ) );
			this.state.requestId = response.request_id;
			this.state.draftToken = response.draft_token;
			return response;
		}

		async saveContactAndPrepareBooking() {
			if ( ! this.stepCanContinue( 'contact_details' ) ) {
				this.state.touched.full_name = true;
				this.state.touched.email = true;
				this.state.touched.phone = true;
				this.render();
				this.setFooterHint( this.contactValidationMessage(), true );
				return;
			}
			try {
				this.state.loading = true;
				this.render();
				const draft = await this.api( 'app/draft', this.buildDraftPayload( 'assistant' ) );
				this.state.requestId = draft.request_id;
				this.state.draftToken = draft.draft_token;
				if ( this.state.photos.length ) {
					this.warmPhotoAnalysis();
				}
				this.state.loading = false;
				this.goTo( 'assistant' );
			} catch ( error ) {
				this.state.loading = false;
				this.render();
				this.setFooterHint( error.message, true );
			}
		}

		async completeBookingStep() {
			await this.checkBookingStatus( true );
		}

		async checkBookingStatus( showMessage ) {
			if ( ! this.state.requestId || ! this.state.draftToken ) {
				return false;
			}

			try {
				const payload = await this.api( 'booking-status', {
					request_id: this.state.requestId,
					draft_token: this.state.draftToken
				} );

				this.state.bookingStatus = payload.status || '';

				if ( payload.is_confirmed ) {
					this.state.bookingStatusMessage = '';
					this.stopBookingStatusPolling();
					return true;
				}

				if ( 'cancelled' === this.state.bookingStatus ) {
					this.setBookingStatusMessage( config.strings.bookingCancelled || 'This booking was cancelled. You can choose another slot below.', true );
				} else if ( showMessage && ! payload.cal_booking_id ) {
					this.setBookingStatusMessage( config.strings.bookingWaiting || 'Finish the booking in the calendar above.', false );
				}

				return false;
			} catch ( error ) {
				if ( showMessage ) {
					this.setBookingStatusMessage( error.message, true );
				}
				return false;
			}
		}

		startBookingStatusPolling() {
			if ( 'booking' !== this.state.step || ! this.state.requestId || ! this.state.draftToken ) {
				return;
			}

			this.stopBookingStatusPolling();
			this.checkBookingStatus( false );
			this.bookingStatusTimer = window.setInterval( () => {
				this.checkBookingStatus( false );
			}, 7000 );
		}

		stopBookingStatusPolling() {
			if ( this.calEmbedTimeoutTimer ) {
				window.clearTimeout( this.calEmbedTimeoutTimer );
				this.calEmbedTimeoutTimer = null;
			}
			if ( this.bookingStatusTimer ) {
				window.clearInterval( this.bookingStatusTimer );
				this.bookingStatusTimer = null;
			}
		}

		setBookingUrl( bookingUrl, source, allowReplace ) {
			const nextUrl = String( bookingUrl || '' ).trim();
			if ( ! nextUrl ) {
				return false;
			}

			if ( this.state.bookingUrl && this.state.bookingUrl !== nextUrl && this.state.bookingUrlLocked && ! allowReplace ) {
				this.logClient( 'warn', 'Ignored changed Cal booking URL after a valid URL was already chosen.', {
					request_id: this.state.requestId,
					source: source || '',
					current_booking_url: this.state.bookingUrl,
					incoming_booking_url: nextUrl
				} );
				return false;
			}

			this.state.bookingUrl = nextUrl;
			this.state.bookingUrlLocked = true;
			return true;
		}

		mergeAssistantResult() {
			const merged = {};
			Array.from( arguments ).forEach( ( source ) => {
				if ( ! source || 'object' !== typeof source ) {
					return;
				}
				Object.keys( source ).forEach( ( key ) => {
					const value = source[ key ];
					if ( null === value || undefined === value ) {
						return;
					}
					if ( 'string' === typeof value && '' === value.trim() && Object.prototype.hasOwnProperty.call( merged, key ) ) {
						return;
					}
					merged[ key ] = value;
				} );
			} );
			return merged;
		}

		applySavedAssistantRouting( normalized, payload, source ) {
			const routing = payload && payload.routing && 'object' === typeof payload.routing ? payload.routing : {};
			const assistantResult = payload && payload.assistant_result && 'object' === typeof payload.assistant_result ? payload.assistant_result : {};
			this.state.assistantResult = this.mergeAssistantResult( this.state.assistantResult, normalized, assistantResult, routing );

			if ( payload && payload.booking_url ) {
				this.setBookingUrl( payload.booking_url, source || 'assistant-routing' );
			}

			const result = this.state.assistantResult || {};
			const hasValidRouting = !! ( result.booking_type && result.duration_bucket && result.suggested_duration_hours );
			const hasReadyUrl = !! ( this.state.bookingUrl && ( ! payload || payload.booking_url_ready || payload.booking_url ) );
			const hasEnoughInformation = true === result.enough_information;
			const isUnsafe = !! ( result.unsafe || ( payload && payload.unsafe_flag ) );
			const assistantReadyForBooking = !! ( payload && payload.assistant_ready_for_booking && hasEnoughInformation && ! isUnsafe && hasReadyUrl && hasValidRouting );

			if ( payload && payload.assistant_result_saved && hasValidRouting ) {
				this.state.assistantResultSaved = true;
				this.state.assistantBookingUrlReady = hasReadyUrl;
				this.state.assistantReadyForBooking = assistantReadyForBooking;
				const ctaEnabled = assistantReadyForBooking && this.state.assistantResponseSeen;
				this.logClient( ctaEnabled ? 'info' : 'debug', ctaEnabled ? 'Booking CTA enabled.' : 'Booking CTA blocked after assistant save.', {
					request_id: this.state.requestId,
					assistant_result_saved: this.state.assistantResultSaved,
					booking_url_ready: hasReadyUrl,
					assistant_ready_for_booking: assistantReadyForBooking,
					enough_information: hasEnoughInformation,
					has_valid_routing: hasValidRouting,
					has_response_seen: this.state.assistantResponseSeen
				} );

				if ( this.state.assistantAwaitingResponse ) {
					const fallbackMessage = result.next_message || ( assistantReadyForBooking ? 'Your booking recommendation is ready.' : 'I need a little more information before we book a time.' );
					this.state.assistantRoutingPending = true;
					if ( this.assistantUnlockTimer ) {
						window.clearTimeout( this.assistantUnlockTimer );
					}
					this.assistantUnlockTimer = window.setTimeout( () => {
						this.assistantUnlockTimer = null;
						if ( this.state.assistantResultSaved && this.state.assistantAwaitingResponse ) {
							this.showAssistantFallbackMessage( fallbackMessage );
							this.state.assistantAwaitingResponse = false;
							this.state.assistantRoutingPending = false;
							this.setAssistantContinueBusy( false );
						}
					}, 3500 );
				} else {
					this.state.assistantRoutingPending = false;
				}
			}

			this.setAssistantContinueBusy( false );
		}

		showAssistantFallbackMessage( message ) {
			const text = String( message || '' ).trim();
			if ( ! text ) {
				return;
			}
			this.state.assistantFallbackMessage = text;
			this.state.assistantResponseSeen = true;
			this.logClient( 'warn', 'Assistant fallback used (silent - chat is the only surface).', {
				request_id: this.state.requestId,
				text_length: text.length
			} );
		}

		clearAssistantFallbackMessage() {
			this.state.assistantFallbackMessage = '';
		}

		setBookingStatusMessage( message, isError ) {
			this.state.bookingStatusMessage = message || '';
			const card = this.root.querySelector( '.handik-booking-app__booking-status' );
			const text = this.root.querySelector( '.handik-booking-app__booking-status-text' );
			if ( text ) {
				text.textContent = this.state.bookingStatusMessage;
			}
			if ( card ) {
				card.classList.toggle( 'is-error', !! isError );
				card.classList.toggle( 'is-success', ! isError && [ 'booked', 'rescheduled' ].includes( this.state.bookingStatus ) );
			}
		}

		loadCalEmbedScript( origin ) {
			if ( window.Cal && 'function' === typeof window.Cal ) {
				return Promise.resolve( window.Cal );
			}

			if ( this.calEmbedPromise ) {
				return this.calEmbedPromise;
			}

			this.calEmbedPromise = new Promise( ( resolve, reject ) => {
				const embedOrigin = origin || 'https://app.cal.com';
				const embedUrl = embedOrigin.replace( /\/+$/, '' ) + '/embed/embed.js';
				const bootstrap = () => {
					if ( window.Cal && 'function' === typeof window.Cal ) {
						return;
					}

					window.Cal = window.Cal || function() {
						const cal = window.Cal;
						const args = arguments;
						const push = function( api, apiArgs ) {
							api.q = api.q || [];
							api.q.push( apiArgs );
						};

						if ( ! cal.loaded ) {
							cal.ns = cal.ns || {};
							cal.q = cal.q || [];
							const existing = document.getElementById( CAL_EMBED_SCRIPT_ID );
							if ( ! existing ) {
								const script = document.createElement( 'script' );
								script.id = CAL_EMBED_SCRIPT_ID;
								script.async = true;
								script.src = embedUrl;
								script.addEventListener( 'error', () => reject( new Error( 'Cal.com embed failed to load.' ) ) );
								document.head.appendChild( script );
							}
							cal.loaded = true;
						}

						if ( 'init' === args[0] ) {
							const namespace = args[1];
							const api = function() {
								push( api, arguments );
							};
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

				let attempts = 0;
				const poll = () => {
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
		}

		parseBookingEmbedConfig() {
			if ( ! this.state.bookingUrl ) {
				return null;
			}

			const bookingUrl = new window.URL( this.state.bookingUrl, window.location.href );
			const calLink = bookingUrl.pathname.replace( /^\/+/, '' );
			const embedConfig = {};
			const ignoredKeys = [ 'overlayCalendar', 'month', 'date', 'slot' ];

			bookingUrl.searchParams.forEach( ( value, key ) => {
				if ( ignoredKeys.includes( key ) ) {
					return;
				}
				if ( 'phone' === key ) {
					const normalizedPhone = String( value || '' ).replace( /[\s()-]+/g, '' );
					embedConfig[ key ] = normalizedPhone && '+' !== normalizedPhone.charAt( 0 ) ? '+' + normalizedPhone : normalizedPhone;
					return;
				}
				embedConfig[ key ] = value;
			} );

			return {
				origin: bookingUrl.origin || 'https://cal.com',
				calLink: calLink,
				config: embedConfig
			};
		}

		getCalNamespaceApi( parsedConfig ) {
			if ( ! parsedConfig || ! window.Cal || 'function' !== typeof window.Cal ) {
				return null;
			}

			const namespace = 'handik_booking_' + String( this.state.requestId || 'draft' );
			this.calEmbedNamespace = namespace;
			window.__handikCalNamespaces = window.__handikCalNamespaces || {};
			window.__handikCalGlobalInit = window.__handikCalGlobalInit || {};

			if ( ! window.__handikCalNamespaces[ namespace ] ) {
				window.Cal( 'init', namespace, { origin: parsedConfig.origin } );
				window.__handikCalNamespaces[ namespace ] = true;
			}

			if ( ! window.__handikCalGlobalInit[ parsedConfig.origin ] ) {
				window.Cal( 'init', { origin: parsedConfig.origin } );
				window.__handikCalGlobalInit[ parsedConfig.origin ] = true;
			}

			return window.Cal.ns && window.Cal.ns[ namespace ] ? window.Cal.ns[ namespace ] : null;
		}

		async captureBookingSuccess( detail ) {
			if ( ! this.state.requestId || ! this.state.draftToken ) {
				return;
			}

			try {
				await this.logClient( 'info', 'Cal embed booking success event fired.', {
					request_id: this.state.requestId,
					booking_id: detail && ( detail.uid || detail.bookingUid || detail.bookingId || detail.id ) ? String( detail.uid || detail.bookingUid || detail.bookingId || detail.id ) : '',
					status: detail && detail.status ? String( detail.status ) : '',
				} );
				const payload = await this.api( 'booking-capture', {
					request_id: this.state.requestId,
					draft_token: this.state.draftToken,
					booking_payload: detail || {}
				} );

				this.state.bookingStatus = payload.status || 'booked';
				this.state.bookingStatusMessage = '';
				this.stopBookingStatusPolling();
				// Booking confirmed — clear the local draft so a future visit starts fresh.
				this.clearDraftStorage();
			} catch ( error ) {
				this.setBookingStatusMessage( error.message || 'We could not save the booking yet.', true );
			}
		}

		mountBookingEmbed() {
			const container = this.root.querySelector( '.handik-booking-app__booking-embed' );
			if ( ! container || ! this.state.bookingUrl ) {
				return;
			}

			const parsedConfig = this.parseBookingEmbedConfig();
			if ( ! parsedConfig || ! parsedConfig.calLink ) {
				container.innerHTML = '<div class="handik-admin-card-like"><p>Booking calendar is not ready yet.</p></div>';
				return;
			}

			const mountKey = String( this.state.requestId ) + '|' + this.state.bookingUrl;
			if ( this.calEmbedMountKey === mountKey ) {
				this.logClient( 'debug', 'Skipped Cal embed remount for unchanged booking URL.', {
					request_id: this.state.requestId,
					mount_key: mountKey
				} );
				return;
			}

			// Show a fallback notice if the embed hasn't reported ready in 15s. We render
			// the notice ABOVE the embed area so a slow-but-eventually-loading iframe still
			// works — we don't replace the container.
			if ( this.calEmbedTimeoutTimer ) {
				window.clearTimeout( this.calEmbedTimeoutTimer );
			}
			this.calEmbedTimeoutTimer = window.setTimeout( () => {
				// Sprint 10 fix: was checking `calEmbedMountKey === mountKey`,
				// but that flag flips as soon as the inline call returns
				// synchronously — well before the iframe inside actually
				// renders. So the check returned "already mounted, skip
				// fallback" even when the iframe was a black hole. Now we
				// track `calEmbedReadyKey`, set only on the `bookerReady`
				// listener, so the fallback fires when the embed isn't
				// truly usable. (`linkReady` happens earlier and isn't
				// proof the booker rendered.)
				if ( this.calEmbedReadyKey === mountKey ) {
					return;
				}
				if ( container.querySelector( '.handik-booking-app__booking-fallback' ) ) {
					return;
				}
				const fallback = document.createElement( 'div' );
				fallback.className = 'handik-booking-app__booking-fallback';
				fallback.setAttribute( 'role', 'status' );
				fallback.innerHTML = '<p>' + this.escape( 'The booking calendar is taking longer than usual.' ) + '</p>' +
					'<p>' + this.escape( 'If it does not appear, please email or call us — your details are saved.' ) + '</p>' +
					'<a class="handik-btn is-secondary" target="_blank" rel="noopener" href="' + this.escape( this.state.bookingUrl ) + '">' + this.escape( 'Open calendar in a new tab' ) + '</a>';
				container.insertBefore( fallback, container.firstChild );
				this.logClient( 'warn', 'Cal embed slow-load fallback shown.', {
					request_id: this.state.requestId,
					booking_url: this.state.bookingUrl
				} );
			}, CAL_EMBED_TIMEOUT_MS );
			this.logClient( 'info', 'Mounting Cal embed.', {
				request_id: this.state.requestId,
				booking_url: this.state.bookingUrl,
				mount_key: mountKey,
			} );
			this.loadCalEmbedScript( parsedConfig.origin ).then( () => {
				const calApi = this.getCalNamespaceApi( parsedConfig );
				if ( ! calApi ) {
					throw new Error( 'Cal.com embed API is not available.' );
				}

				if ( this.calEmbedListenerKey !== mountKey ) {
					// Sprint 10 fix: register listeners on `calApi` ONLY
					// (the namespaced API). Old code did `registerListener
					// (window.Cal); registerListener(calApi);` — both
					// inherit each other, so each event ended up firing
					// twice; with `bookingSuccessful` + `bookingSuccessfulV2`
					// emitted near-simultaneously that meant 4 captureBooking
					// calls per real success. The server side is idempotent
					// per booking-id, but the client-side toast surfaced
					// twice. Single registration is enough.
					const registerListener = ( api ) => {
						if ( ! api || 'function' !== typeof api ) {
							return;
						}
						const handleSuccess = ( event ) => {
							const detail = event && event.detail && event.detail.data ? event.detail.data : {};
							const bookingId = String(
								detail.bookingId
									|| detail.bookingUid
									|| detail.uid
									|| detail.id
									|| ''
							);
							// Sprint 10 fix: idempotency on the client too.
							// The server is idempotent per booking id, but
							// `bookingSuccessful` + `bookingSuccessfulV2`
							// fire near-simultaneously so the same toast
							// could surface twice in the same render tick.
							if ( bookingId && this._lastCapturedBookingId === bookingId ) {
								return;
							}
							if ( bookingId ) {
								this._lastCapturedBookingId = bookingId;
							}
							this.captureBookingSuccess( detail );
						};
						api( 'on', { action: 'bookingSuccessfulV2', callback: handleSuccess } );
						api( 'on', { action: 'bookingSuccessful',   callback: handleSuccess } );
						api( 'on', {
							action: 'linkReady',
							callback: () => {
								this.logClient( 'info', 'Cal embed link ready.', {
									request_id: this.state.requestId,
									namespace: this.calEmbedNamespace,
								} );
							}
						} );
						api( 'on', {
							action: 'bookerReady',
							callback: ( event ) => {
								const detail = event && event.detail && event.detail.data ? event.detail.data : {};
								// Sprint 10 fix: this is the real "iframe
								// has rendered the slot picker" signal; the
								// 15s slow-load fallback uses this as
								// proof-of-life (was using mountKey, which
								// flips synchronously and missed black-iframe
								// failures).
								this.calEmbedReadyKey = mountKey;
								if ( this.calEmbedTimeoutTimer ) {
									window.clearTimeout( this.calEmbedTimeoutTimer );
									this.calEmbedTimeoutTimer = null;
								}
								this.logClient( 'info', 'Cal embed booker ready.', {
									request_id: this.state.requestId,
									event_slug: detail && detail.eventSlug ? String( detail.eventSlug ) : '',
								} );
							}
						} );
						api( 'on', {
							action: 'linkFailed',
							callback: ( event ) => {
								const detail = event && event.detail && event.detail.data ? event.detail.data : {};
								const message = detail.msg || 'Cal.com could not load the booking calendar.';
								this.setBookingStatusMessage( message, true );
								this.notify( 'error', config.strings.bookingTitle || 'Book your time slot', message );
								this.logClient( 'error', 'Cal embed link failed.', {
									request_id: this.state.requestId,
									code: detail.code || '',
									message: message,
								} );
							}
						} );
					};

					registerListener( calApi );
					this.calEmbedListenerKey = mountKey;
				}

				container.innerHTML = '';
				calApi( 'inline', {
					elementOrSelector: container,
					calLink: parsedConfig.calLink,
					config: parsedConfig.config
				} );
				this.logClient( 'info', 'Cal embed mounted.', {
					request_id: this.state.requestId,
					cal_link: parsedConfig.calLink,
					namespace: this.calEmbedNamespace,
				} );
				this.calEmbedMountKey = mountKey;
				if ( this.calEmbedTimeoutTimer ) {
					window.clearTimeout( this.calEmbedTimeoutTimer );
					this.calEmbedTimeoutTimer = null;
				}
			} ).catch( ( error ) => {
				container.innerHTML = '<div class="handik-booking-app__booking-frame-wrap"><iframe class="handik-booking-app__booking-frame" src="' + this.escape( this.state.bookingUrl ) + '" title="Cal.com booking calendar" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="fullscreen"></iframe></div>';
				this.logClient( 'error', 'Cal embed mount failed, using iframe fallback.', {
					request_id: this.state.requestId,
					error: error && error.message ? error.message : 'unknown',
				} );
			} );
		}

		async completeAssistantStep() {
			if ( ! this.state.requestId || ! this.state.draftToken ) {
				this.setFooterHint( 'Assistant draft is not ready yet.', true );
				return;
			}

			const gate = this.assistantGateState();
			if ( ! gate.allowed ) {
				this.setAssistantContinueBusy( false );
				const logMessage = [
					'no user message',
					'no saved result',
					'enough_information false',
					'booking_url missing'
				].includes( gate.reason ) ? 'Assistant continue blocked: ' + gate.reason : 'Assistant continue blocked: ' + ( gate.reason || 'not ready' );
				this.logClient( 'info', logMessage, this.assistantGateLogContext( gate ) );
				if ( 'no user message' === gate.reason ) {
					this.setAssistantNotice( 'Please describe your job to the AI assistant first. It will help estimate the time, cost, and right booking type before you choose a time.', true );
				} else if ( 'enough_information false' === gate.reason ) {
					this.setAssistantNotice( 'Please answer the assistant follow-up before choosing a time.', true );
				} else if ( 'booking_url missing' === gate.reason ) {
					this.setAssistantNotice( 'The booking recommendation is not ready yet. Please wait for the assistant to finish.', true );
				} else {
					this.setAssistantNotice( 'Please wait for the assistant to finish reviewing your request.', true );
				}
				return;
			}

			try {
				this.logClient( 'info', 'Assistant continue allowed', this.assistantGateLogContext( gate ) );
				this.state.loading = true;
				this.setAssistantContinueBusy( true );
				this.state.loading = false;
				this.setAssistantContinueBusy( false );

				this.state.bookingStatus = 'booking_pending';
				this.state.bookingStatusMessage = '';
				this.goTo( 'booking' );
			} catch ( error ) {
				this.state.loading = false;
				this.setAssistantContinueBusy( false );
				this.setAssistantNotice( error.message || 'We could not save the assistant step yet.', true );
			}
		}

		buildDraftPayload( appStep ) {
			return {
				request_id: this.state.requestId,
				draft_token: this.state.draftToken,
				client_type: this.state.isReturningClient ? 'returning_client' : 'new_client',
				selected_tasks: this.state.selectedTasks,
				is_project: this.state.isProject,
				job_shape: this.state.jobShape,
				preferred_timeframe: '',
				address_id: this.state.address.address_id,
				address_full: this.state.address.address_full,
				address_line_1: this.state.address.address_line_1,
				address_unit: this.state.address.address_unit,
				city: this.state.address.city,
				state: this.state.address.state,
				zip_code: this.state.address.zip_code,
				short_description: '',
				photos: this.state.photos,
				first_name: this.state.contact.first_name,
				last_name: this.state.contact.last_name,
				full_name: this.state.contact.full_name,
				email: this.state.contact.email,
				phone: this.phoneApiValue( this.state.contact.phone ),
				app_step: appStep,
				app_session_key: this.state.appSessionKey,
				app_state: this.state
			};
		}

		mountAssistant() {
			const container = this.root.querySelector( '.handik-booking-app__assistant-host' );
			if ( ! container || ! this.state.requestId || ! this.state.draftToken || ! window.HandikChatKitBridge ) {
				return;
			}

			if ( this.assistantMountPromise ) {
				return;
			}

			this.assistantMountPromise = Promise.resolve().then( () => {
				this.logClient( 'info', 'Assistant mounted.', {
					request_id: this.state.requestId
				} );
				// Sprint 1 A3: hand the prewarmed session payload to the bridge so
				// the very first getClientSecret() returns synchronously instead
				// of doing another create-session round-trip.
				const prewarmedRequestId = Number( this.assistantPrewarmedRequestId ) || 0;
				const prewarmedSession = ( prewarmedRequestId === Number( this.state.requestId ) )
					? this.assistantPrewarmedSession
					: null;
				if ( prewarmedSession ) {
					this.assistantPrewarmedSession = null;
					this.assistantPrewarmedRequestId = 0;
				}
				this.assistantBridge = window.HandikChatKitBridge.mount( {
					container: container,
					requestId: this.state.requestId,
					draftToken: this.state.draftToken,
					cacheKey: 'request_' + String( this.state.requestId ),
					prewarmedSession: prewarmedSession,
					initialThreadId: this.state.assistantThreadId,
					startScreenGreeting: config.strings.assistantGreeting || 'Describe the job.',
					composerPlaceholder: config.strings.assistantGreeting || 'Describe the job.',
					// Hardcoded English: keep the bridge loader copy out of the
					// stale-localization path. Owner can still customize the
					// assistant intro / greeting through admin settings.
					loadingTitle: 'Loading virtual assistant…',
					loadingSubtitle: '',
					endpoints: {
						createSession: config.restBase + 'chatkit-session',
						requestPhotoContext: config.restBase + 'request-photo-context',
						requestPricingContext: config.restBase + 'request-pricing-context',
						saveAssistantResult: config.restBase + 'assistant-result',
						associateThread: config.restBase + 'chatkit-thread',
						clientLog: config.restBase + 'client-log'
					},
					onSessionReady: ( session ) => {
						this.setAssistantPreparingState( false );
						window.setTimeout( () => this.unlockAssistantAfterPhotoContext( session ), 0 );
					},
					onThreadChange: ( threadId ) => {
						this.state.assistantThreadId = threadId || this.state.assistantThreadId;
						this.setAssistantContinueBusy( false );
					},
					onMessageActivity: ( detail ) => {
						const role = detail && ( detail.role || detail.author_role || ( detail.message && detail.message.role ) ) ? String( detail.role || detail.author_role || detail.message.role ).toLowerCase() : '';
						const messageType = detail && ( detail.type || detail.message_type || ( detail.message && detail.message.type ) ) ? String( detail.type || detail.message_type || detail.message.type ).toLowerCase() : '';
						const direction = detail && ( detail.direction || ( detail.message && detail.message.direction ) ) ? String( detail.direction || detail.message.direction ).toLowerCase() : '';
						const source = detail && ( detail.source || detail.origin || ( detail.message && ( detail.message.source || detail.message.origin ) ) ) ? String( detail.source || detail.origin || detail.message.source || detail.message.origin ).toLowerCase() : '';
						// 2.1.29.1 P0 fix: the original "contains" checks were
						// `false !== messageType.indexOf( 'user' )`. String.indexOf
						// returns -1 (not false) when the needle is missing, so
						// `false !== -1` evaluates to true for every messageType
						// value — both isUserLike AND isAssistantLike were always
						// true, the if/else-if always picked the user branch, and
						// assistant message events never cleared the status block
						// or restored assistantReadyForBooking. Fix: use the
						// canonical `-1 !== indexOf` "contains" check.
						const isUserLike = 'user' === role || -1 !== messageType.indexOf( 'user' ) || 'outgoing' === direction || 'user' === source || 'client' === source;
						const isAssistantLike = 'assistant' === role || -1 !== messageType.indexOf( 'assistant' ) || -1 !== messageType.indexOf( 'output' ) || 'incoming' === direction || 'assistant' === source;
						if ( isUserLike ) {
							this.startAssistantStatusBlock();
							this.state.assistantUserMessageSent = true;
							this.state.assistantRoutingPending = true;
							this.state.assistantAwaitingResponse = true;
							this.state.assistantResponseSeen = false;
							this.state.assistantReadyForBooking = false;
							this.clearAssistantFallbackMessage();
							this.clearAssistantStuckBanner();
							if ( this.assistantUnlockTimer ) {
								window.clearTimeout( this.assistantUnlockTimer );
								this.assistantUnlockTimer = null;
							}
							this.setAssistantContinueBusy( false );
						} else if ( isAssistantLike ) {
							this.clearAssistantStatusBlock();
							this.logClient( 'info', 'Assistant final message detected.', {
								request_id: this.state.requestId
							} );
							this.state.assistantResponseSeen = true;
							this.state.assistantAwaitingResponse = false;
							this.state.assistantRoutingPending = ! this.state.assistantResultSaved;
							this.clearAssistantFallbackMessage();
							this.clearAssistantStuckBanner();
							if ( this.assistantUnlockTimer ) {
								window.clearTimeout( this.assistantUnlockTimer );
								this.assistantUnlockTimer = null;
							}
							this.setAssistantContinueBusy( false );
						}
					},
					onComposerSubmit: () => {
						this.startAssistantStatusBlock();
						this.logClient( 'info', 'User submitted assistant message.', {
							request_id: this.state.requestId
						} );
						this.state.assistantUserMessageSent = true;
						this.state.assistantRoutingPending = true;
						this.state.assistantAwaitingResponse = true;
						this.state.assistantResponseSeen = false;
						this.state.assistantReadyForBooking = false;
						this.clearAssistantFallbackMessage();
						this.clearAssistantStuckBanner();
						if ( this.assistantUnlockTimer ) {
							window.clearTimeout( this.assistantUnlockTimer );
							this.assistantUnlockTimer = null;
						}
						this.setAssistantContinueBusy( false );
					},
					onComplete: ( normalized, payload ) => {
						this.clearAssistantStatusBlock();
						this.applySavedAssistantRouting( normalized, payload, 'chatkit-complete' );
						this.state.assistantUserMessageSent = true;
						if ( payload && payload.unsafe_flag ) {
							this.state.unsafeReason = payload.unsafe_reason || 'Unsafe request detected.';
							this.goTo( 'unsafe' );
							return;
						}
					},
					onError: ( error ) => {
						this.clearAssistantStatusBlock();
						this.setAssistantPreparingState( false );
						this.setAssistantNotice( error.message || 'The virtual assistant had trouble loading. Give it another moment, then send a short message about the job.', true );
						// Issue 5: bridge couldn't mount → show the Plan B banner
						// so the customer can still get to the booking page even
						// without the assistant.
						this.showAssistantStuckBanner( 'mount-failed' );
					}
				} );

				return this.assistantBridge && this.assistantBridge.ready ? this.assistantBridge.ready.catch( () => {} ) : null;
			} ).finally( () => {
				this.assistantMountPromise = null;
			} );
		}

		progressMarkup() {
			if ( 'unsafe' === this.state.step ) {
				return '';
			}
			const steps = this.applicableSteps();
			const activeIndex = Math.max( 0, steps.indexOf( this.state.step ) );
			// Sprint 9 fix: pass the actual step count to CSS via a custom
			// property so the grid always lays the dots out on a single row,
			// regardless of how many steps `applicableSteps()` returns.
			return '<ol class="handik-progress-dots" aria-label="Booking progress" style="--handik-progress-step-count:' + steps.length + ';">' +
				steps.map( ( step, index ) => '<li class="' + ( index <= activeIndex ? 'is-done' : '' ) + ( index === activeIndex ? ' is-current' : '' ) + '"></li>' ).join( '' ) +
			'</ol>';
		}

		applicableSteps() {
			// Sprint 6: phone-first OTP gates entry to address_details. The
			// new 'otp_verify' step lives between contact_details (now phone-only)
			// and address_details (now branched: returning vs new client).
			//
			// Sprint 9 fix: when the customer was already restored from the
			// 30-day verified-client cache (or just finished OTP and is
			// navigating back), drop `contact_details` and `otp_verify`
			// from the timeline. The progress bar then shows the 5 steps
			// they actually take, and the contact step never appears as a
			// dot the user can reach via back-navigation. Owner-reported:
			// previously the cache restored the profile but the SPA still
			// asked for the phone again at contact_details, defeating the
			// "no second OTP for 30 days" promise.
			if ( this.state.phoneVerified ) {
				return [ 'task_selection', 'photos', 'address_details', 'assistant', 'booking' ];
			}
			return [ 'task_selection', 'photos', 'contact_details', 'otp_verify', 'address_details', 'assistant', 'booking' ];
		}

		render() {
			const shellClasses = [
				'handik-booking-app__shell',
				'handik-booking-app__shell--' + String( this.state.step || 'unknown' ).replace( /[^a-z0-9_-]/gi, '-' ).toLowerCase()
			];
			if ( 'task_selection' === this.state.step ) {
				shellClasses.push( 'handik-booking-app__shell--task-' + String( this.state.taskSelectionMode || 'overview' ).replace( /[^a-z0-9_-]/gi, '-' ).toLowerCase() );
			}
			this.root.innerHTML = '<div class="' + shellClasses.join( ' ' ) + '">' + this.stepMarkup() + '<div class="handik-global-progress">' + this.progressMarkup() + '</div>' + this.appFooterDisclaimer() + this.restartModalMarkup() + '</div>';
			this.bind();
			this.renderNotifications();
			if ( this.state.selectedTasksSheetAnimate ) {
				window.setTimeout( () => {
					this.state.selectedTasksSheetAnimate = false;
					if ( 'task_selection' === this.state.step && this.state.selectedTasks.length && ! this.state.selectedTasksSheetOpen && ! this.state.selectedTasksSheetAttentionDismissed ) {
						this.render();
					}
				}, 2900 );
			}
			if ( 'assistant' === this.state.step ) {
				this.prepareAssistantStep();
			}
			if ( 'address_details' === this.state.step ) {
				window.setTimeout( () => this.mountAddressAutocomplete(), 0 );
				this.prewarmAssistantSession();
			}
			if ( 'booking' === this.state.step ) {
				window.setTimeout( () => this.startBookingStatusPolling(), 0 );
				window.setTimeout( () => this.mountBookingEmbed(), 0 );
			} else {
				this.stopBookingStatusPolling();
			}
		}

		stepMarkup() {
			switch ( this.state.step ) {
				case 'task_selection':
					return this.screen( config.strings.taskTitle || 'What do you need help with?', this.tasksMarkup() );
				case 'address_details':
					return this.screen( config.strings.addressTitle || 'Address details', this.addressMarkup() );
				case 'photos':
					return this.screen( config.strings.photosTitle || 'Photos / Videos of the Work Area', this.photosMarkup() );
				case 'assistant':
					return this.screen( config.strings.assistantTitle || 'Virtual assistant', this.assistantMarkup(), 'is-wide' );
				case 'contact_details':
					return this.screen( config.strings.contactTitle || 'Your phone', this.contactMarkup() );
				case 'otp_verify':
					return this.screen( config.strings.otpStepTitle || 'Enter the code', this.otpVerifyMarkup() );
				case 'booking':
					return this.screen( config.strings.bookingTitle || 'Book your time slot', this.bookingMarkup() );
				case 'unsafe':
					// Sprint 11 fix: route the support email through config so an
				// install with a different owner address (or non-Handik
				// branding) doesn't leak alex@handik.pro into the public
				// SPA. Falls back to the historical hard-coded address so
				// nothing breaks on existing installs.
				const supportEmail = ( config.strings && config.strings.supportEmail ) || 'alex@handik.pro';
				const unsafeContact = ( config.strings && config.strings.unsafeContactBody )
					? this.escape( config.strings.unsafeContactBody ).replace( '%s', '<a href="mailto:' + this.escape( supportEmail ) + '">' + this.escape( supportEmail ) + '</a>' )
					: ( 'If this seems wrong, please email <a href="mailto:' + this.escape( supportEmail ) + '">' + this.escape( supportEmail ) + '</a> and we\'ll sort it out.' );
				return this.screen( config.strings.unsafeTitle || 'We need a closer look', '<p class="handik-booking-app__intro">' + this.escape( this.state.unsafeReason || config.strings.unsafeBody || 'This request needs manual review before booking.' ) + '</p><div class="handik-unsafe-actions"><button data-action="back-from-unsafe" class="handik-btn is-secondary">' + this.escape( 'Go back and adjust' ) + '</button><button data-action="restart" class="handik-btn is-secondary">' + this.escape( config.strings.restart || 'Start another booking' ) + '</button></div><p class="handik-booking-app__intro">' + unsafeContact + '</p>' );
				default:
					return this.screen( 'Booking App', '<p>Unknown step.</p>' );
			}
		}

		screen( title, body, modifier ) {
			// Generic loader overlay for non-assistant blocking states.
			const genericOverlayNeeded = this.state.loading || this.state.photoUploading;
			const genericOverlay = genericOverlayNeeded ? '<div class="handik-booking-app__loading-overlay" aria-live="polite">' + this.loaderMarkup( 'Loading' ) + '</div>' : '';
			// Issue 3 fix: assistant overlay is always rendered in DOM and
			// toggled via .is-assistant-preparing on the body. The label is
			// hardcoded English here on purpose — the public app must not
			// surface a stale Russian "ui_loading_assistant_title" setting.
			const isAssistantStep = 'assistant' === this.state.step;
			const assistantOverlay = isAssistantStep ? '<div class="handik-booking-app__loading-overlay handik-booking-app__loading-overlay--assistant" data-assistant-preparing-overlay="1" aria-live="polite">' + this.loaderMarkup( 'Loading virtual assistant…' ) + '</div>' : '';
			const bodyClass = 'handik-booking-app__screen-body' + ( isAssistantStep && this.state.assistantPreparing ? ' is-assistant-preparing' : '' );
			const stepClass = 'handik-booking-app__screen--' + String( this.state.step || 'unknown' ).replace( /[^a-z0-9_-]/gi, '-' ).toLowerCase();
			return '<section class="handik-booking-app__screen ' + stepClass + ' ' + ( modifier || '' ) + '"><div class="handik-booking-app__screen-header"><h2>' + this.escape( title ) + '</h2></div><div class="' + bodyClass + '">' + body + assistantOverlay + genericOverlay + '</div></section>';
		}

		directBookingUrl() {
			if ( this.state.bookingUrl ) {
				return this.state.bookingUrl;
			}
			return config.calFallbackUrl || ( this.bootstrap && this.bootstrap.cal_fallback_url ) || '';
		}

		appFooterDisclaimer() {
			const isBooked = 'booking' === this.state.step && 'booked' === this.state.bookingStatus;
			if ( isBooked ) {
				return '<aside class="handik-app-disclaimer is-success"><p>' + this.escape( 'All set. Alex will be in touch before the visit.' ) + '</p><p><a href="#" data-action="restart" class="handik-text-link">' + this.escape( 'Book another visit' ) + '</a></p></aside>';
			}

			const directUrl = this.directBookingUrl();
			const restartLink = '<a href="#" data-action="restart" class="handik-text-link">' + this.escape( 'Start a new booking' ) + '</a>';
			const directLink = directUrl ? '<a href="' + this.escape( directUrl ) + '" target="_blank" rel="noopener" class="handik-text-link">' + this.escape( 'Open the booking page directly' ) + '</a>' : '';
			const links = directLink ? restartLink + '<span class="handik-app-disclaimer__sep" aria-hidden="true"> · </span>' + directLink : restartLink;
			return '<aside class="handik-app-disclaimer"><p>' + this.escape( 'Stuck? ' ) + links + '</p></aside>';
		}

		restartModalMarkup() {
			if ( ! this.state.restartConfirmVisible ) {
				return '';
			}
			return '<div class="handik-modal-backdrop" role="presentation"><section class="handik-modal" role="dialog" aria-modal="true" aria-label="' + this.escape( 'Start over?' ) + '">' +
				'<h3>' + this.escape( 'Start over?' ) + '</h3>' +
				'<p>' + this.escape( 'This will clear the current booking draft and start a new booking.' ) + '</p>' +
				'<div class="handik-modal__actions"><button type="button" class="handik-btn is-secondary" data-action="restart-cancel">' + this.escape( 'Cancel' ) + '</button><button type="button" class="handik-btn is-primary" data-action="restart-confirm">' + this.escape( 'Yes, restart' ) + '</button></div>' +
			'</section></div>';
		}

		input( label, model, type, modifier, helpText, errorText ) {
			const value = this.getByPath( model ) || '';
			const attrs = this.inputAttrsForModel( model, type );
			const isInvalid = String( modifier || '' ).split( /\s+/ ).includes( 'is-invalid' );
			const id = this.instanceId + '-' + model.replace( /[^a-z0-9_-]/gi, '-' );
			const errorId = id + '-error';
			const describedBy = isInvalid && errorText ? ' aria-describedby="' + this.escape( errorId ) + '"' : '';
			const invalidAttr = isInvalid ? ' aria-invalid="true"' : '';
			return '<label class="handik-field ' + this.escape( modifier || '' ) + '"><span>' + this.escape( label ) + '</span><input id="' + this.escape( id ) + '" type="' + this.escape( type || 'text' ) + '" data-model="' + this.escape( model ) + '" value="' + this.escape( value ) + '"' + attrs + invalidAttr + describedBy + ' />' + ( helpText ? '<span class="handik-field__help">' + this.escape( helpText ) + '</span>' : '' ) + ( isInvalid && errorText ? '<span id="' + this.escape( errorId ) + '" class="handik-field__error" role="alert">' + this.escape( errorText ) + '</span>' : '' ) + '</label>';
		}

		inputAttrsForModel( model, type ) {
			// Pair every contact field with the right autofill / mobile keyboard hints.
			// Server normalizes phone via Handik_Booking_App_Contacts_Service::normalize_phone.
			const attrs = {};
			switch ( model ) {
				case 'contact.full_name':
					attrs.autocomplete = 'name';
					attrs.autocapitalize = 'words';
					attrs.spellcheck = 'false';
					attrs.placeholder = config.strings.fullNamePlaceholder || 'Jane Smith';
					break;
				case 'contact.first_name':
					attrs.autocomplete = 'given-name';
					attrs.autocapitalize = 'words';
					break;
				case 'contact.last_name':
					attrs.autocomplete = 'family-name';
					attrs.autocapitalize = 'words';
					break;
				case 'contact.email':
					attrs.autocomplete = 'email';
					attrs.inputmode = 'email';
					attrs.autocapitalize = 'off';
					attrs.spellcheck = 'false';
					attrs.placeholder = config.strings.emailPlaceholder || 'you@example.com';
					break;
				case 'contact.phone':
					attrs.autocomplete = 'tel';
					attrs.inputmode = 'tel';
					attrs.placeholder = config.strings.phonePlaceholder || '+1 555 123 4567';
					break;
				/* Sprint 11 fix: removed an unreachable duplicate
				 * `case 'contact.full_name'` that PHPStan / ESLint
				 * would flag and that was confusing to readers. */
				case 'otpCode':
					// SMS-autofill hint, numeric keypad on mobile, no autocaps.
					attrs.autocomplete = 'one-time-code';
					attrs.inputmode = 'numeric';
					attrs.maxlength = '8';
					attrs.pattern = '[0-9]*';
					attrs.autocapitalize = 'off';
					attrs.spellcheck = 'false';
					attrs.placeholder = config.strings.otpPlaceholder || '6-digit code';
					break;
				default:
					if ( 'email' === type ) {
						attrs.autocomplete = 'email';
						attrs.inputmode = 'email';
					} else if ( 'tel' === type ) {
						attrs.autocomplete = 'tel';
						attrs.inputmode = 'tel';
					}
			}

			let out = '';
			Object.keys( attrs ).forEach( ( key ) => {
				out += ' ' + key + '="' + this.escape( attrs[ key ] ) + '"';
			} );
			return out;
		}

		getByPath( path ) {
			return path.split( '.' ).reduce( ( current, key ) => current && current[ key ], this.state );
		}

		tasksMarkup() {
			if ( 'specific' !== this.state.taskSelectionMode ) {
				return '<p class="handik-booking-app__intro">' + this.escape( config.strings.taskIntro || 'Choose the option that best matches your request.' ) + '</p>' +
					'<div class="handik-choice-grid handik-choice-grid--task-paths">' +
						this.taskPathChoiceMarkup( 'choose-general-handyman', 'General Handyman Help', 'For mixed, unclear, or everyday handyman tasks', '$80/hr', this.taskSelected( 'general_handyman_help' ) ) +
						this.taskPathChoiceMarkup( 'choose-specific-tasks', 'Choose Specific Tasks', 'Browse services by category and select one or more tasks', 'Price depends on task', false ) +
						this.taskPathChoiceMarkup( 'choose-larger-scale', 'Free Consultation', 'Free on-site visit to assess larger or unclear work before booking.', 'Free', this.taskSelected( 'larger_scale_work' ) ) +
					'</div>';
			}

			const hiddenSpecificTaskIds = [ 'general_handyman_help', 'larger_scale_work' ];
			const groups = ( ( this.bootstrap && this.bootstrap.task_catalog ) || [] ).map( ( group ) => {
				return Object.assign( {}, group, {
					tasks: ( group.tasks || [] ).filter( ( task ) => ! hiddenSpecificTaskIds.includes( task.id ) )
				} );
			} ).filter( ( group ) => group.tasks.length );
			return '<p class="handik-booking-app__intro">' + this.escape( 'Tap services to add or remove them.' ) + '</p><div class="handik-task-groups">' +
				groups.map( ( group ) => '<div class="handik-task-group"><h3>' + this.escape( group.group ) + '</h3><div class="handik-task-grid">' +
					group.tasks.map( ( task ) => '<button type="button" class="handik-task ' + ( this.taskSelected( task.id ) ? 'is-selected' : '' ) + '" data-task-id="' + this.escape( task.id ) + '">' + this.escape( task.label ) + '</button>' ).join( '' ) +
				'</div></div>' ).join( '' ) +
				this.footerActions( 'back-tasks', 'tasks-next', this.escape( config.strings.continue ), '', { continueMuted: ! this.stepCanContinue( 'task_selection' ) } ) +
				this.selectedTasksSheetMarkup() +
			'</div>';
		}

		addressMarkup() {
			// Sprint 6: address_details is now the combined "details" screen
			// for the post-OTP flow. Returning customers (verified profile)
			// see the saved-address picker; new customers also see name +
			// email fields above the address inputs (phone is already
			// verified, so it's gone from this screen).
			const addressOptions = this.state.verifiedProfile && Array.isArray( this.state.verifiedProfile.addresses ) ? this.state.verifiedProfile.addresses : [];
			const isReturningProfile = !! ( this.state.verifiedProfile && this.state.verifiedProfile.contact );
			const newClientFields = ( ! isReturningProfile && this.state.phoneVerified )
				? this.input( config.strings.fullNameLabel || 'Full name', 'contact.full_name', 'text',
						this.isFieldInvalid( 'full_name' ) ? 'is-invalid' : '', '',
						this.contactFieldError( 'full_name' ) ) +
					this.input( config.strings.emailLabel || 'Email (optional)', 'contact.email', 'email',
						this.isFieldInvalid( 'email' ) ? 'is-invalid' : '', '',
						this.contactFieldError( 'email' ) )
				: '';
			const addressAntiAutofillAttrs = ' autocomplete="new-password" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-form-type="other" name="handik_job_location_query"';
			const unitAntiAutofillAttrs = ' autocomplete="new-password" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-form-type="other" name="handik_job_unit_detail"';
			let savedAddressMarkup = '';
			if ( isReturningProfile && this.state.savedAddressLoading ) {
				savedAddressMarkup = '<div class="handik-saved-address-loading" role="status" aria-live="polite"><div class="handik-saved-address-loading__bar"></div><div class="handik-saved-address-loading__bar is-short"></div><span>' + this.escape( 'Checking saved addresses...' ) + '</span></div>';
			} else if ( addressOptions.length ) {
				savedAddressMarkup = '<label class="handik-field"><span>' + this.escape( config.strings.savedAddressLabel || 'Choose a saved address or enter a new one' ) + '</span><select id="handik-saved-address" autocomplete="off"><option value="">' + this.escape( config.strings.savedAddressPlaceholder || 'Choose saved address' ) + '</option>' + addressOptions.map( ( item ) => '<option value="' + item.id + '">' + this.escape( this.composeSavedAddress( item ) ) + '</option>' ).join( '' ) + '</select></label>';
			} else if ( isReturningProfile ) {
				savedAddressMarkup = '<p class="handik-field__help handik-field__help--empty" role="status">' + this.escape( 'No saved addresses yet — enter the address below.' ) + '</p>';
			}

			// Sprint 10 fix: persistent inline ZIP-not-serviced error. Was a
			// 4.2s auto-dismissing toast that left the customer staring at
			// a Continue button that did nothing. Now the address field
			// flips to invalid state with a stable, descriptive message
			// naming the offending ZIP, and stays visible until they pick
			// a different address (handler in onInput + place_changed).
			const zipBlocked = this.state.address.zipBlocked && this.state.address.zipBlocked === this.state.address.zip_code
				? this.state.address.zipBlocked
				: '';

			return (
				newClientFields +
				savedAddressMarkup +
				'<label class="handik-field handik-field--address' + ( zipBlocked || ( this.state.address.address_full && ! this.state.address.is_valid ) ? ' is-invalid' : '' ) + '"><span>' + this.escape( config.strings.addressLabel || 'Address of the job' ) + '</span><input id="handik-job-address" type="text" data-model="address.address_full"' + addressAntiAutofillAttrs + ' placeholder="' + this.escape( config.strings.addressPlaceholder || 'Start typing the address of the job' ) + '" value="' + this.escape( this.state.address.address_full || '' ) + '" />' +
					( zipBlocked
						? '<span class="handik-field__error" role="alert">' + this.escape(
							( config.strings.errors && config.strings.errors.zipNotServiced )
								|| ( 'We don\'t currently provide service to ZIP ' + zipBlocked + '. Try a different address, or email '
									+ ( ( config.strings && config.strings.supportEmail ) || 'alex@handik.pro' )
									+ ' to discuss your project.' )
						) + '</span>'
						: ( this.state.address.address_full && ! this.state.address.is_valid
							? '<span class="handik-field__error" role="alert">' + this.escape( ( config.strings.errors && config.strings.errors.addressRequired ) || 'Choose a valid address from the suggestions to continue.' ) + '</span>'
							: '' )
					) +
				'</label>' +
				'<label class="handik-field"><span>' + this.escape( config.strings.unitLabel || 'Unit or apartment (optional)' ) + '</span><input type="text" data-model="address.address_unit"' + unitAntiAutofillAttrs + ' value="' + this.escape( this.state.address.address_unit || '' ) + '" /></label>' +
				this.footerActions( 'back-address', 'address-next', this.escape( config.strings.continue ), '', { continueMuted: ! this.stepCanContinue( 'address_details' ) } )
			);
		}

		photosMarkup() {
			const ctaLabel = config.strings.photosCta || 'Add photos or videos';
			const photosMarkup = this.state.photos.length
				? '<ul class="handik-photo-list">' + this.state.photos.map( ( photo, index ) => {
					const photoId = photo.id || photo.attachment_id || photo.url || ( 'photo-' + index );
					const photoName = photo.name || photo.url || 'file';
					const mediaLabel = photo.media_type || photo.type || ( photo.mime_type && 0 === String( photo.mime_type ).indexOf( 'video/' ) ? 'video' : 'photo' );
					return '<li class="handik-photo-list__item"><span class="handik-photo-list__name">' + this.escape( photoName ) + '</span>' +
						'<span class="handik-photo-list__type">' + this.escape( mediaLabel ) + '</span>' +
						'<button type="button" class="handik-photo-list__remove" data-action="remove-photo" data-photo-id="' + this.escape( String( photoId ) ) + '" aria-label="' + this.escape( 'Remove ' + photoName ) + '">&times;</button>' +
						'</li>';
				} ).join( '' ) + '</ul>'
				: '<div class="handik-photo-list is-empty"><span>' + this.escape( config.strings.photosEmpty || 'No photos or videos added yet' ) + '</span></div>';
			return '<p class="handik-booking-app__intro">' + this.escape( config.strings.photosIntro || 'Upload photos or short videos of the problem area, item, fixture, wall, appliance, or installation spot.' ) + '</p>' +
				// Sprint 11 fix: unify "Loading…" glyph (was mixing "Loading…",
			// "Loading...", and "Loading"). One ellipsis character (…), no
			// spaces, used everywhere a busy state is rendered.
			'<label class="handik-field handik-field--media"><input type="file" id="handik-photo-input" class="handik-photo-input" multiple accept="image/jpeg,image/jpg,image/png,image/webp,image/heic,.jpg,.jpeg,.png,.webp,.heic,video/mp4,video/quicktime,video/webm,.mp4,.mov,.webm" /><button type="button" class="handik-photo-dropzone" data-action="choose-photos"><span class="handik-photo-dropzone__icon" aria-hidden="true"></span><span>' + this.escape( ctaLabel ) + '</span><span class="handik-field__help">' + this.escape( 'Up to 8 photos or videos · Photos to 10 MB · Videos to 50 MB' ) + '</span>' + ( this.state.photoUploading ? '<span>' + this.escape( config.strings.uploading || 'Loading…' ) + '</span><span class="handik-inline-spinner" aria-hidden="true"></span>' : '' ) + '</button>' + photosMarkup + '</label>' +
				this.footerActions( 'back-photos', 'photos-next', this.escape( config.strings.continue ), this.escape( config.strings.back ), { continueMuted: ! this.stepCanContinue( 'photos' ) } );
		}

		assistantMarkup() {
			const skeleton = '<div class="handik-skeleton handik-skeleton--assistant" aria-hidden="true"><div class="handik-skeleton__bar"></div><div class="handik-skeleton__bar handik-skeleton__bar--short"></div><div class="handik-skeleton__bar"></div></div>';
			return '<p class="handik-booking-app__intro">' + this.escape( config.strings.assistantIntro || 'This AI assistant helps you understand rough cost, timing, materials, and what to expect, while helping Alex collect the details needed to prepare for the job properly.' ) + '</p><div class="handik-assistant-layout"><div class="handik-assistant-panel"><div class="handik-booking-app__assistant-host">' + skeleton + '</div>' + this.footerActions( 'back-assistant', 'assistant-next', this.escape( config.strings.assistantContinue || 'Book a time' ), '', { continueMuted: ! this.assistantCanContinue() } ) + '</div></div>';
		}

		contactMarkup() {
			// Sprint 6: contact_details is now phone-only. SMS code goes
			// next via /phone-verify/start. Returning customers will
			// auto-skip to address_details on success.
			if ( this.state.verifiedProfile && this.state.verifiedProfile.contact && ! this.state.contact.full_name ) {
				this.prefillFromProfile();
			}
			const phoneError = this.isFieldInvalid( 'phone' ) ? this.contactFieldError( 'phone' ) : '';
			const introCopy = config.strings.phoneStepIntro || "We'll text you a one-time code to confirm.";
			return '<p class="handik-booking-app__intro">' + this.escape( introCopy ) + '</p>' +
				this.input( config.strings.phoneLabel || 'Phone', 'contact.phone', 'tel',
					this.isFieldInvalid( 'phone' ) ? 'is-invalid' : '',
					'', phoneError ) +
				this.footerActions( '', 'contact-next',
					this.escape( config.strings.sendCodeCta || 'Send code' ), '',
					{ continueMuted: ! this.stepCanContinue( 'contact_details' ), hideBack: true } );
		}

		/**
		 * OTP step. 6-digit code entry + Verify CTA + Resend (30s lockout)
		 * + "Use a different number" link. Mirrors the Additional Forms
		 * implementation byte-for-byte.
		 */
		otpVerifyMarkup() {
			const error = this.state.otpError ? this.state.otpError : '';
			// Sprint 10 fix: rate-limit errors get a distinct visual
			// (amber, not red) so the customer doesn't keep mashing the
			// keypad against a backend that's already locked them out
			// for a few minutes.
			const errorKind = this.state.otpErrorKind || 'invalid';
			const errorClass = error
				? ( 'rate_limit' === errorKind ? 'is-invalid is-rate-limited' : 'is-invalid' )
				: '';
			const now = Date.now();
			const resendIn = Math.max( 0, Math.ceil( ( this.state.otpResendDisabledUntil - now ) / 1000 ) );
			const introTpl = config.strings.otpIntro || 'Enter the 6-digit code we just sent to %s.';
			const intro = introTpl.replace( '%s', this.escape( this.state.contact.phone ) );
			return '<p class="handik-booking-app__intro">' + intro + '</p>' +
				this.input( config.strings.otpCodeLabel || 'Verification code', 'otpCode', 'text',
					errorClass,
					'', error ) +
				'<div class="handik-booking-app__otp-aux">' +
					( resendIn > 0
						? '<span class="handik-booking-app__otp-resend is-pending">' +
							this.escape( ( config.strings.otpResendIn || 'You can resend in %ds' ).replace( '%d', String( resendIn ) ) ) +
						'</span>'
						: '<button type="button" class="handik-text-link" data-action="otp-resend">' +
							this.escape( config.strings.otpResendCta || 'Resend code' ) +
						'</button>' ) +
					'<span class="handik-app-disclaimer__sep" aria-hidden="true"> · </span>' +
					'<button type="button" class="handik-text-link" data-action="otp-back">' +
						this.escape( config.strings.otpDifferentNumberCta || 'Use a different number' ) +
					'</button>' +
				'</div>' +
				// Hotfix 2.1.13.1: the Verify button is hidden — the moment
				// the customer types the 6th digit (or the SMS-autofill chip
				// is tapped) the input handler calls verifyPhoneOtp directly.
				// Owners reported the visible-but-disabled state was a
				// dead-end UX (looked tappable, did nothing), and a stale
				// state.otpCode (the bug we just fixed in setFieldValue) made
				// it stay disabled in older builds.
				this.footerActions( '', 'otp-verify',
					this.escape( config.strings.verifyCta || 'Verify' ), '',
					{ continueMuted: ! this.stepCanContinue( 'otp_verify' ), hideBack: true, hideContinue: true } );
		}

		bookingMarkup() {
			const skeleton = '<div class="handik-skeleton handik-skeleton--calendar" aria-hidden="true">' +
				'<div class="handik-skeleton__bar handik-skeleton__bar--header"></div>' +
				'<div class="handik-skeleton__grid">' +
				Array.from( { length: 9 } ).map( () => '<div class="handik-skeleton__cell"></div>' ).join( '' ) +
				'</div></div>';
			return '<div class="handik-booking-app__booking-embed">' + skeleton + '</div>';
		}

		footerActions( backAction, continueAction, continueLabel, backLabel, options ) {
			const settings = options || {};
			const backText = this.escape( settings.backLabel || 'Back' );
			const backClass = ( settings.backIsUtility ? 'handik-btn is-secondary' : 'handik-btn is-secondary is-back' ) + ( settings.backMuted ? ' is-disabled' : '' );
			const backInner = settings.backIsUtility ? '<span class="handik-btn__label">' + backText + '</span>' : '<span class="handik-btn__icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M19 11H7.83l4.88-4.88L11.29 4.7 4 12l7.29 7.3 1.42-1.42L7.83 13H19v-2z"></path></svg></span><span class="handik-btn__label">' + backText + '</span>';
			const continueClass = 'handik-btn ' + ( settings.continueMuted ? 'is-pending' : 'is-primary' ) + ' is-continue';
			const backButton = settings.hideBack ? '' : '<button data-action="' + this.escape( backAction ) + '" class="' + backClass + '"' + ( settings.backMuted ? ' aria-disabled="true"' : '' ) + '>' + backInner + '</button>';
			const utilityButton = settings.utilityAction ? '<button data-action="' + this.escape( settings.utilityAction ) + '" class="handik-btn is-text">' + this.escape( settings.utilityLabel || '' ) + '</button>' : '';
			const continueButton = settings.hideContinue ? '' : '<div class="handik-footer-actions__continue">' + utilityButton + '<button data-action="' + this.escape( continueAction ) + '" class="' + continueClass + '" aria-disabled="' + ( settings.continueMuted ? 'true' : 'false' ) + '">' + continueLabel + '</button></div>';
			const actions = backButton || continueButton ? '<div class="handik-footer-actions is-docked' + ( settings.hideBack || settings.hideContinue ? ' is-single' : '' ) + '">' + backButton + continueButton + '</div>' : '';
			return '<div class="handik-footer-wrap">' + actions + '</div>';
		}

		normalizePhone( value ) {
			return this.formatPhoneDisplay( value );
		}

		async loadGoogleMapsPlaces() {
			if ( ! config.googleMapsApiKey ) {
				return null;
			}

			if ( window.google && window.google.maps && window.google.maps.places ) {
				return window.google.maps;
			}

			if ( this.googleMapsPromise ) {
				return this.googleMapsPromise;
			}

			this.googleMapsPromise = new Promise( ( resolve, reject ) => {
				const existingScript = document.getElementById( GOOGLE_SCRIPT_ID );
				if ( existingScript ) {
					existingScript.addEventListener( 'load', () => resolve( window.google && window.google.maps ? window.google.maps : null ), { once: true } );
					existingScript.addEventListener( 'error', () => reject( new Error( 'Google Maps script failed to load.' ) ), { once: true } );
					return;
				}

				const script = document.createElement( 'script' );
				script.id = GOOGLE_SCRIPT_ID;
				script.async = true;
				script.defer = true;
				script.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent( config.googleMapsApiKey ) + '&libraries=places';
				script.onload = () => resolve( window.google && window.google.maps ? window.google.maps : null );
				script.onerror = () => reject( new Error( 'Google Maps script failed to load.' ) );
				document.head.appendChild( script );
			} );

			return this.googleMapsPromise;
		}

		parseAddressComponents( place ) {
			const components = Array.isArray( place.address_components ) ? place.address_components : [];
			const componentValue = ( type, useShort ) => {
				const match = components.find( ( item ) => Array.isArray( item.types ) && item.types.includes( type ) );
				if ( ! match ) {
					return '';
				}
				return useShort ? ( match.short_name || '' ) : ( match.long_name || '' );
			};

			const streetNumber = componentValue( 'street_number', false );
			const route = componentValue( 'route', false );
			const subpremise = componentValue( 'subpremise', false );
			const lineOne = [ streetNumber, route ].filter( Boolean ).join( ' ' ).trim();

			return {
				address_full: place.formatted_address || this.state.address.address_full || '',
				address_line_1: lineOne || place.formatted_address || '',
				address_unit: subpremise || this.state.address.address_unit || '',
				city: componentValue( 'locality', false ) || componentValue( 'postal_town', false ) || componentValue( 'sublocality_level_1', false ),
				state: componentValue( 'administrative_area_level_1', true ),
				zip_code: componentValue( 'postal_code', false ),
				is_valid: !! (
					place.formatted_address &&
					lineOne &&
					componentValue( 'postal_code', false ) &&
					componentValue( 'administrative_area_level_1', true ) &&
					place.geometry &&
					place.geometry.location
				)
			};
		}

		async mountAddressAutocomplete() {
			const input = this.root.querySelector( '#handik-job-address' );
			if ( ! input || ! config.googleMapsApiKey ) {
				return;
			}
			this.disableNativeAddressAutofill( input );

			if ( '1' === input.getAttribute( 'data-google-mounted' ) ) {
				return;
			}

			try {
				await this.loadGoogleMapsPlaces();
				if ( ! window.google || ! window.google.maps || ! window.google.maps.places ) {
					return;
				}

				this.addressAutocomplete = new window.google.maps.places.Autocomplete(
					input,
					{
						fields: [ 'address_components', 'formatted_address', 'geometry' ],
						types: [ 'address' ],
						componentRestrictions: config.googleMapsCountry ? { country: config.googleMapsCountry } : undefined
					}
				);

				this.addressAutocomplete.addListener( 'place_changed', () => {
					const place = this.addressAutocomplete.getPlace();
					if ( ! place ) {
						return;
					}

					this.state.address = Object.assign( {}, this.state.address, this.parseAddressComponents( place ) );
					// Sprint 10 fix: clear the persistent ZIP-not-serviced
					// badge when the customer picks a new Places result;
					// the next Continue evaluates the new ZIP fresh.
					this.state.address.zipBlocked = '';
					input.value = this.state.address.address_full || input.value;
					this.render();
				} );

				input.setAttribute( 'data-google-mounted', '1' );
				this.disableNativeAddressAutofill( input );
			} catch ( error ) {
				this.googleMapsPromise = Promise.resolve( null );
				console.error( '[HandikBookingApp] Google Maps autocomplete failed.', error );
			}
		}

		disableNativeAddressAutofill( input ) {
			if ( ! input ) {
				return;
			}
			input.setAttribute( 'autocomplete', 'new-password' );
			input.setAttribute( 'autocorrect', 'off' );
			input.setAttribute( 'autocapitalize', 'off' );
			input.setAttribute( 'spellcheck', 'false' );
			input.setAttribute( 'data-lpignore', 'true' );
			input.setAttribute( 'data-1p-ignore', 'true' );
			input.setAttribute( 'data-form-type', 'other' );
			input.setAttribute( 'name', 'handik_job_location_query' );
		}

		bind() {
			// Sprint 7 (a11y): release any prior modal focus trap before
			// rebinding. The SPA does a full innerHTML re-render on every
			// state change, so the modal's DOM node is replaced — we have to
			// re-arm the trap each time the dialog is visible (and tear down
			// any leftover keydown listener from a previous render).
			if ( this._releaseModalFocusTrap ) {
				this._releaseModalFocusTrap();
				this._releaseModalFocusTrap = null;
			}
			// Sprint 10 fix: the OTP "Resend in Xs" copy is computed once at
			// render time, so the customer stared at a frozen number until
			// they tapped a field. A 1-second ticker re-renders the OTP step
			// while the lockout is active and stops itself when it expires
			// (or when the step changes). One interval at a time.
			if ( this._otpResendTicker ) {
				window.clearInterval( this._otpResendTicker );
				this._otpResendTicker = null;
			}
			if ( 'otp_verify' === this.state.step && this.state.otpResendDisabledUntil > Date.now() ) {
				this._otpResendTicker = window.setInterval( () => {
					if ( 'otp_verify' !== this.state.step ) {
						window.clearInterval( this._otpResendTicker );
						this._otpResendTicker = null;
						return;
					}
					if ( this.state.otpResendDisabledUntil <= Date.now() ) {
						window.clearInterval( this._otpResendTicker );
						this._otpResendTicker = null;
						this.render();
						return;
					}
					// Surgical update: only the resend-pending span changes.
					const remaining = Math.max( 0, Math.ceil( ( this.state.otpResendDisabledUntil - Date.now() ) / 1000 ) );
					const pending = this.root.querySelector( '.handik-booking-app__otp-resend.is-pending' );
					if ( pending ) {
						const tpl = ( config.strings.otpResendIn || 'You can resend in %ds' );
						pending.textContent = tpl.replace( '%d', String( remaining ) );
					}
				}, 1000 );
			}
			if ( this.state.restartConfirmVisible ) {
				const dialog = this.root.querySelector( '.handik-modal' );
				const backdrop = this.root.querySelector( '.handik-modal-backdrop' );
				if ( dialog ) {
					this._releaseModalFocusTrap = trapModalFocus( dialog );
					// Move focus to Cancel so a screen-reader announces the
					// dialog and Enter doesn't accidentally confirm restart.
					const cancelBtn = dialog.querySelector( '[data-action="restart-cancel"]' );
					if ( cancelBtn ) {
						window.requestAnimationFrame( () => cancelBtn.focus() );
					}
				}
				// Sprint 10 fix: ESC + backdrop click both dismiss the
				// modal. Was missing entirely on the main SPA — mobile
				// users on 320px had no X button and had to find the
				// Cancel CTA at the bottom of the dialog.
				if ( this._restartEscHandler ) {
					document.removeEventListener( 'keydown', this._restartEscHandler, true );
				}
				this._restartEscHandler = ( event ) => {
					if ( 'Escape' === event.key && this.state.restartConfirmVisible ) {
						event.preventDefault();
						this.state.restartConfirmVisible = false;
						this.render();
					}
				};
				document.addEventListener( 'keydown', this._restartEscHandler, true );
				if ( backdrop ) {
					backdrop.addEventListener( 'click', ( event ) => {
						if ( event.target === backdrop ) {
							this.state.restartConfirmVisible = false;
							this.render();
						}
					} );
				}
			} else if ( this._restartEscHandler ) {
				document.removeEventListener( 'keydown', this._restartEscHandler, true );
				this._restartEscHandler = null;
			}
			this.root.querySelectorAll( '[data-action]' ).forEach( ( button ) => {
				button.addEventListener( 'click', ( event ) => {
					event.preventDefault();
					this.handleAction( button.getAttribute( 'data-action' ), button );
				} );
			} );

			this.root.querySelectorAll( '[data-task-id]' ).forEach( ( button ) => {
				button.addEventListener( 'click', () => this.toggleTask( button.getAttribute( 'data-task-id' ) ) );
			} );

			this.root.querySelectorAll( '[data-model]' ).forEach( ( input ) => {
				const eventName = 'checkbox' === input.type || 'select-one' === input.type ? 'change' : 'input';
				input.addEventListener( eventName, () => {
					// Ignore events from a field detached by a prior re-render
					// (e.g. Google Places firing its bind-time `input` on the
					// old address input after the customer picked a saved
					// address). Acting on its stale value would clobber the
					// freshly-applied address state.
					if ( false === input.isConnected ) { return; }
					let value = 'checkbox' === input.type ? input.checked : input.value;
					const model = input.getAttribute( 'data-model' );
					if ( 'contact.full_name' === model ) {
						value = String( value || '' ).replace( /[^\p{L}\s'.-]/gu, '' ).replace( /\s{2,}/g, ' ' );
						input.value = value;
					}
					if ( 'contact.phone' === model ) {
						const allowed = String( value || '' ).replace( /[^0-9+\s()-]/g, '' );
						const selectionStart = input.selectionStart || allowed.length;
						const digitsBefore = allowed.slice( 0, selectionStart ).replace( /\D/g, '' ).length;
						const formatted = allowed;
						input.value = formatted;
						let caret = formatted.length;
						let seen = 0;
						for ( let i = 0; i < formatted.length; i++ ) {
							if ( /\d/.test( formatted[ i ] ) ) {
								seen++;
							}
							if ( seen >= digitsBefore ) {
								caret = i + 1;
								break;
							}
						}
						if ( ! digitsBefore ) {
							caret = input.selectionStart || formatted.length;
						}
						try {
							input.setSelectionRange( caret, caret );
						} catch ( e ) {
							// Some inputs (number, email) reject setSelectionRange — ignore.
						}
						value = formatted;
						if ( this.phoneDigits( value ) !== this.state.lastLookupPhone ) {
							this.state.verifiedProfile = null;
							this.state.isReturningClient = false;
						}
					}
					// Only invalidate when the text actually changed. A stray
					// same-value `input` (Places bind, autofill re-fill) is not
					// a real edit and must not un-verify a saved / Places-picked
					// address; otherwise Continue stops working right after the
					// customer picks a saved address.
					if ( 'address.address_full' === model && String( value ) !== String( this.state.address.address_full || '' ) ) {
						this.state.address.is_valid = false;
						this.state.address.address_id = 0;
						// Sprint 10 fix: clear the persistent "ZIP not in
						// service area" badge as soon as the customer
						// re-types — the new address will get a fresh ZIP
						// and a fresh evaluation when they hit Continue.
						this.state.address.zipBlocked = '';
					}
					this.setByPath( model, value );
					this.refreshFieldValidation( model, input );
					this.refreshCurrentStepActions();
					this._scheduleDraftSave();
					// Hotfix 2.1.13.1: the legacy /contacts/lookup call from the
					// phone field used to fire BEFORE the OTP step, which leaked
					// "Welcome back — we found your details." into the UI before
					// the customer had even verified their phone (PII signal +
					// confusing ordering). The OTP-check response now carries
					// the same profile, and verifyPhoneOtp() owns the welcome
					// notification, so we no longer trigger lookup from input.
					// Hotfix 2.1.13.1: auto-advance the OTP step at 6 digits
					// (Twilio default). The Verify button is removed in this
					// build; typing the last digit (or accepting the SMS
					// autofill chip) verifies immediately.
					if ( 'otpCode' === model && 'otp_verify' === this.state.step ) {
						const otpDigits = String( value || '' ).replace( /\D/g, '' );
						if ( otpDigits.length >= 6 && ! this.state.loading && ! this._otpVerifyInFlight ) {
							this.state.otpCode = otpDigits.slice( 0, 6 );
							input.value = this.state.otpCode;
							this.verifyPhoneOtp();
						}
					}
				} );

				const model = input.getAttribute( 'data-model' );
				// 2.1.27.0 (A3 P0 fix): defer the render() via rAF to
				// avoid the mobile Continue-button race. Touch tap on
				// Continue first fires `blur` on the field the customer
				// just edited; a synchronous render() here destroys the
				// Continue button node before the queued click event
				// lands, so the click hits a detached element and
				// silently no-ops. The defer lets the click drain
				// first, the action handler runs (which renders
				// itself), and this blur-driven refresh ends up a
				// no-op for the common "Continue after editing a
				// field" path. Error spans still clear when the field
				// becomes valid because the action handler's render
				// captures the same state. Mirrors the Forms SPA fix.
				const deferRender = () => {
					if ( typeof window.requestAnimationFrame === 'function' ) {
						window.requestAnimationFrame( () => this.render() );
					} else {
						window.setTimeout( () => this.render(), 0 );
					}
				};
				if ( 'contact.full_name' === model ) {
					input.addEventListener( 'blur', () => {
						this.state.touched.full_name = true;
						deferRender();
					} );
				}
				if ( 'contact.phone' === model ) {
					input.addEventListener( 'blur', () => {
						this.state.touched.phone = true;
						deferRender();
						// Hotfix 2.1.13.1: see note in the input handler — lookup
						// belongs to the post-OTP path now, not contact_details.
					} );
				}
				if ( 'contact.email' === model ) {
					input.addEventListener( 'blur', () => {
						this.state.touched.email = true;
						deferRender();
					} );
				}
			} );

			const savedAddress = this.root.querySelector( '#handik-saved-address' );
			if ( savedAddress ) {
				savedAddress.addEventListener( 'change', () => {
					const chosen = ( this.state.verifiedProfile.addresses || [] ).find( ( item ) => String( item.id ) === savedAddress.value );
					if ( chosen ) {
						this.state.address = {
							address_id: chosen.id,
							address_full: this.composeSavedAddress( chosen ),
							address_line_1: chosen.address_line_1 || '',
							address_unit: chosen.address_unit || '',
							city: chosen.city || '',
							state: chosen.state || '',
							zip_code: chosen.zip_code || '',
							is_valid: true
						};
						this.render();
					}
				} );
			}

			const photoInput = this.root.querySelector( '#handik-photo-input' );
			if ( photoInput ) {
				photoInput.addEventListener( 'change', async () => {
					if ( ! photoInput.files.length ) {
						return;
					}
					const selectedFiles = Array.from( photoInput.files || [] );
					photoInput.value = '';
					await this.handleSelectedPhotos( selectedFiles, 'Photos added', '' );
				} );
			}

		}

		escape( value ) {
			return String( value || '' )
				.replace( /&/g, '&amp;' )
				.replace( /</g, '&lt;' )
				.replace( />/g, '&gt;' )
				.replace( /"/g, '&quot;' );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function() {
		document.querySelectorAll( '.handik-booking-app' ).forEach( function( root ) {
			const app = new HandikBookingApp( root );
			app.init();
		} );
	} );
}( window, document ) );
