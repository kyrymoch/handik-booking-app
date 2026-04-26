( function( window, document ) {
	'use strict';

	const config = window.HandikBookingAppConfig || {};
	const GOOGLE_SCRIPT_ID = 'handik-google-maps-places';
	const CAL_EMBED_SCRIPT_ID = 'handik-cal-embed-script';
	const DRAFT_STORAGE_KEY = 'handik_booking_app_draft_v1';
	const DRAFT_TTL_MS = 24 * 60 * 60 * 1000; // 24 hours.
	const CAL_EMBED_TIMEOUT_MS = 15000;
	const PERSISTED_STATE_FIELDS = [
		'step',
		'clientType',
		'requestId',
		'draftToken',
		'selectedTasks',
		'taskSelectionMode',
		'isProject',
		'jobShape',
		'address',
		'photos',
		'shortDescription',
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
			this.calEmbedListenerKey = '';
			this.assistantPreparationPromise = null;
			this.assistantMountPromise = null;
			this.pendingAssistantContextAnalysis = null;
			this.notificationTimers = new Map();
			this.assistantBridge = null;
			this.photoAnalysisWarmRequestId = 0;
			this.photoAnalysisWarmPromise = null;
			this.photoAnalysisWarmPromiseRequestId = 0;
			this.assistantContextDispatchPromise = null;
			this.state = {
				step: 'client_type',
				clientType: '',
				verifiedProfile: null,
				requestId: 0,
				draftToken: '',
				selectedTasks: [],
				taskSelectionMode: 'overview',
				selectedTasksSheetOpen: false,
				selectedTasksSheetAnimate: false,
				isProject: false,
				jobShape: '',
				address: { address_id: 0, address_full: '', address_line_1: '', address_unit: '', city: '', state: '', zip_code: '', is_valid: false },
				photos: [],
				shortDescription: '',
				assistantResult: null,
				assistantUserMessageSent: false,
				assistantThreadId: '',
				contact: { first_name: '', last_name: '', full_name: '', email: '', phone: '' },
				touched: { full_name: false, email: false, phone: false },
				verificationCode: '',
				bookingUrl: '',
				bookingStatus: '',
				bookingStatusMessage: '',
				unsafeReason: '',
				appSessionKey: 'app_' + Math.random().toString( 36 ).slice( 2 ),
				message: '',
				footerHint: '',
				footerHintError: false,
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
			try {
				this.bootstrap = await this.api( 'app/bootstrap', {}, 'GET' );
				if ( this.bootstrap.verified_profile ) {
					this.state.verifiedProfile = this.bootstrap.verified_profile;
				}
				this.render();
				this.replaceHistoryState( this.state.step );
				this.focusStepHeading();
			} catch ( error ) {
				this.root.innerHTML = '<div class="handik-booking-app__shell"><div class="handik-booking-app__alert is-error" role="alert">' + this.escape( error.message ) + '</div></div>';
			}
		}

		focusStepHeading() {
			window.requestAnimationFrame( () => {
				const heading = this.root.querySelector( '.handik-booking-app__screen-header h2' );
				if ( ! heading ) {
					return;
				}
				heading.setAttribute( 'tabindex', '-1' );
				try {
					heading.focus( { preventScroll: true } );
				} catch ( e ) {
					heading.focus();
				}
			} );
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
			this.state.assistantPreparing = !! isPreparing;
			if ( 'assistant' !== this.state.step ) {
				return;
			}

			const body = this.root.querySelector( '.handik-booking-app__screen-body' );
			if ( ! body ) {
				return;
			}

			let overlay = body.querySelector( '[data-assistant-preparing-overlay]' );
			if ( isPreparing ) {
				if ( ! overlay ) {
					const wrapper = document.createElement( 'div' );
					wrapper.innerHTML = '<div class="handik-booking-app__loading-overlay" data-assistant-preparing-overlay="1" aria-live="polite">' + this.loaderMarkup( 'Loading' ) + '</div>';
					overlay = wrapper.firstChild;
					body.appendChild( overlay );
				}
				return;
			}

			if ( overlay ) {
				overlay.remove();
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
				meta: Array.isArray( settings.meta ) ? settings.meta : []
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
				button.disabled = !! isBusy;
				button.textContent = isBusy ? ( config.strings.loading || 'Loading...' ) : ( config.strings.assistantContinue || 'Book a time' );
				button.classList.remove( 'is-pending' );
				button.classList.add( 'is-primary' );
				button.setAttribute( 'aria-disabled', isBusy ? 'true' : 'false' );
			}
		}

		assistantCanContinue() {
			return !! (
				( this.state.assistantResult && true === this.state.assistantResult.enough_information ) ||
				this.state.assistantUserMessageSent ||
				this.state.assistantThreadId
			);
		}

		stepCanContinue( step ) {
			switch ( step ) {
				case 'client_type':
					return !! this.state.clientType;
				case 'returning_verify':
					return !! ( ( this.state.contact.email || this.state.contact.phone ) && this.state.verificationCode );
				case 'task_selection':
					return !! ( this.state.selectedTasks.length || this.state.isProject );
				case 'address_details':
					return !! ( this.state.address.address_full && this.state.address.is_valid && ! this.state.photoUploading );
				case 'photos':
					return ! this.state.photoUploading;
				case 'assistant':
					return true;
				case 'contact_details':
					return this.validateContactFields().valid;
				default:
					return true;
			}
		}

		stepBlockMessage( step ) {
			const errors = config.strings.errors || {};
			switch ( step ) {
				case 'client_type':
					return errors.pickClientType || 'Check this step. Choose whether you are a new client or a returning client to continue.';
				case 'returning_verify':
					return errors.invalidCode || 'Check this step. Enter your code and verify to continue.';
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
			return /^[\p{L}][\p{L}\s'-]*$/u.test( normalized );
		}

		validateEmail( value ) {
			return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( String( value || '' ).trim() );
		}

		phoneDigits( value ) {
			let digits = String( value || '' ).replace( /\D/g, '' );
			if ( digits.length > 10 && '1' === digits.charAt( 0 ) ) {
				digits = digits.slice( 1 );
			}
			return digits.slice( 0, 10 );
		}

		formatPhoneDisplay( value ) {
			const digits = this.phoneDigits( value );
			if ( ! digits ) {
				return '';
			}

			const parts = [];
			if ( digits.length > 0 ) {
				parts.push( digits.slice( 0, Math.min( 3, digits.length ) ) );
			}
			if ( digits.length > 3 ) {
				parts.push( digits.slice( 3, Math.min( 6, digits.length ) ) );
			}
			if ( digits.length > 6 ) {
				parts.push( digits.slice( 6, Math.min( 8, digits.length ) ) );
			}
			if ( digits.length > 8 ) {
				parts.push( digits.slice( 8, Math.min( 10, digits.length ) ) );
			}

			return '+1 ' + parts.join( ' ' );
		}

		phoneApiValue( value ) {
			const digits = this.phoneDigits( value );
			return 10 === digits.length ? '+1' + digits : '';
		}

		validatePhone( value ) {
			return 10 === this.phoneDigits( value ).length;
		}

		validateContactFields() {
			const fullName = String( this.state.contact.full_name || '' ).trim();
			const email = String( this.state.contact.email || '' ).trim();
			const phone = String( this.state.contact.phone || '' ).trim();
			return {
				full_name: this.validateFullName( fullName ),
				email: this.validateEmail( email ),
				phone: this.validatePhone( phone ),
				valid: this.validateFullName( fullName ) && this.validateEmail( email ) && this.validatePhone( phone ),
			};
		}

		isFieldInvalid( fieldName ) {
			const validation = this.validateContactFields();
			return !! ( this.state.touched && this.state.touched[ fieldName ] && false === validation[ fieldName ] );
		}

		contactValidationMessage() {
			const errors = config.strings.errors || {};
			const validation = this.validateContactFields();
			if ( ! validation.full_name ) {
				return errors.invalidName || 'Check this step. Enter a real full name using letters only.';
			}
			if ( ! validation.email ) {
				return errors.invalidEmail || 'Check this step. Enter a valid email address before continuing.';
			}
			if ( ! validation.phone ) {
				return errors.invalidPhone || 'Check this step. Enter a phone number in the format +1 123 456 78 90.';
			}
			return errors.nameEmailRequired || 'Check this step. Name, email, and phone are required before you can continue.';
		}

		clientTypeChoiceMarkup( type, action, label ) {
			const isSelected = type === this.state.clientType;
			return '<div class="handik-choice-wrap"><button data-action="' + this.escape( action ) + '" class="handik-choice ' + ( isSelected ? 'is-selected' : '' ) + '"><span class="handik-choice__title">' + this.escape( label ) + '</span></button></div>';
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
				window.scrollTo( {
					top: top,
					behavior: 'smooth'
				} );
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
			return '<div class="handik-selected-sheet' + ( expanded ? ' is-open' : '' ) + ( this.state.selectedTasksSheetAnimate ? ' is-bouncing' : '' ) + '">' +
				'<button type="button" class="handik-selected-sheet__toggle" data-action="toggle-selected-tasks" aria-expanded="' + ( expanded ? 'true' : 'false' ) + '">' +
					'<span>Selected tasks</span>' +
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
					this.goTo( 'client_type' );
					break;
				case 'choose-new':
					this.state.clientType = 'new_client';
					this.goTo( 'task_selection' );
					break;
				case 'choose-returning':
					this.state.clientType = 'returning_client';
					this.goTo( 'returning_verify' );
					break;
				case 'client-type-next':
					if ( ! this.state.clientType ) {
						this.showStepWarning( 'client_type' );
						return;
					}
					this.goTo( 'returning_client' === this.state.clientType ? 'returning_verify' : 'address_details' );
					break;
				case 'choose-general-handyman':
					this.state.selectedTasks = [ 'general_handyman_help' ];
					this.state.taskSelectionMode = 'overview';
					this.state.selectedTasksSheetOpen = false;
					this.state.selectedTasksSheetAnimate = false;
					this.state.isProject = false;
					this.state.jobShape = 'single_task';
					this.goTo( 'address_details' );
					break;
				case 'choose-larger-scale':
					this.state.selectedTasks = [ 'larger_scale_work' ];
					this.state.taskSelectionMode = 'overview';
					this.state.selectedTasksSheetOpen = false;
					this.state.selectedTasksSheetAnimate = false;
					this.state.isProject = true;
					this.state.jobShape = 'project';
					this.goTo( 'address_details' );
					break;
				case 'choose-specific-tasks':
					this.state.selectedTasks = [];
					this.state.taskSelectionMode = 'specific';
					this.state.selectedTasksSheetOpen = false;
					this.state.selectedTasksSheetAnimate = false;
					this.state.isProject = false;
					this.state.jobShape = '';
					this.render();
					this.scrollStepIntoView();
					break;
				case 'send-code':
					await this.sendCode();
					break;
				case 'verify-code':
					await this.verifyCode();
					break;
				case 'tasks-next':
					if ( ! this.stepCanContinue( 'task_selection' ) ) {
						this.showStepWarning( 'task_selection' );
						return;
					}
					this.state.jobShape = this.state.isProject ? 'project' : ( this.state.selectedTasks.length > 1 ? 'multiple_tasks' : 'single_task' );
					this.goTo( 'address_details' );
					break;
				case 'address-next':
					if ( ! this.stepCanContinue( 'address_details' ) ) {
						this.showStepWarning( 'address_details' );
						return;
					}
					this.goTo( 'photos' );
					break;
				case 'photos-next':
					await this.saveAddressAndDraft();
					break;
				case 'contact-next':
					await this.saveContactAndPrepareBooking();
					break;
				case 'open-booking':
					if ( this.state.bookingUrl ) {
						window.open( this.state.bookingUrl, '_blank', 'noopener,noreferrer' );
						this.state.bookingOpened = true;
						this.setBookingStatusMessage( config.strings.bookingWaiting || 'Stay on this screen while we wait for Cal.com to confirm the booking.', false );
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
					this.stopBookingStatusPolling();
					this.clearDraftStorage();
					if ( window.HandikChatKitBridge && typeof window.HandikChatKitBridge.reset === 'function' && this.state.requestId ) {
						window.HandikChatKitBridge.reset( 'request_' + String( this.state.requestId ) );
					}
					this.photoAnalysisWarmRequestId = 0;
					this.photoAnalysisWarmPromise = null;
					this.photoAnalysisWarmPromiseRequestId = 0;
					this.assistantContextDispatchPromise = null;
					this.assistantMountPromise = null;
					this.pendingAssistantContextAnalysis = null;
					this.state = Object.assign( this.state, {
						step: 'client_type',
						clientType: '',
						requestId: 0,
						draftToken: '',
						selectedTasks: [],
						taskSelectionMode: 'overview',
						selectedTasksSheetOpen: false,
						selectedTasksSheetAnimate: false,
						isProject: false,
						jobShape: '',
						address: { address_id: 0, address_full: '', address_line_1: '', address_unit: '', city: '', state: '', zip_code: '', is_valid: false },
						photos: [],
						shortDescription: '',
						assistantResult: null,
						assistantUserMessageSent: false,
						assistantThreadId: '',
						contact: { first_name: '', last_name: '', full_name: '', email: '', phone: '' },
						verificationCode: '',
						bookingUrl: '',
						bookingStatus: '',
						bookingStatusMessage: '',
						unsafeReason: '',
						message: '',
						lastAssistantNotice: '',
						assistantPreparing: false,
						footerHint: '',
						footerHintError: false,
						photoUploading: false,
						bookingOpened: false
					} );
					this.goTo( 'client_type' );
					break;
				case 'back-start':
					this.setFooterHint( '', false );
					break;
				case 'back-client':
					this.goTo( 'client_type' );
					break;
				case 'back-returning':
					this.goTo( 'returning_verify' );
					break;
				case 'back-tasks':
					if ( 'specific' === this.state.taskSelectionMode ) {
						this.state.taskSelectionMode = 'overview';
						this.render();
						this.scrollStepIntoView();
					} else {
						this.goTo( 'client_type' );
					}
					break;
				case 'back-address':
					this.goTo( 'task_selection' );
					break;
				case 'back-photos':
					this.goTo( 'address_details' );
					break;
				case 'back-contact':
					this.goTo( 'photos' );
					break;
				case 'toggle-project':
					break;
				case 'toggle-selected-tasks':
					this.state.selectedTasksSheetOpen = ! this.state.selectedTasksSheetOpen;
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

		async sendCode() {
			if ( ! this.state.contact.email && ! this.state.contact.phone ) {
				this.setFooterHint( ( config.strings.errors && config.strings.errors.phoneOrEmailRequired ) || 'Enter your email or phone, then request a code.', true );
				return;
			}
			try {
				this.state.loading = true;
				this.render();
				const response = await this.api( 'auth/request-code', {
					email: this.state.contact.email,
					phone: this.phoneApiValue( this.state.contact.phone ),
					redirect: window.location.href
				} );
				this.setFooterHint( response.message, false );
			} catch ( error ) {
				this.setFooterHint( error.message, true );
			}
			this.state.loading = false;
			this.render();
		}

		async verifyCode() {
			if ( ! this.stepCanContinue( 'returning_verify' ) ) {
				this.setFooterHint( ( config.strings.errors && config.strings.errors.invalidCode ) || 'Enter your code and verify to continue.', true );
				return;
			}
			try {
				this.state.loading = true;
				this.render();
				const response = await this.api( 'auth/verify', {
					email: this.state.contact.email,
					phone: this.phoneApiValue( this.state.contact.phone ),
					code: this.state.verificationCode
				} );
				this.state.verifiedProfile = response.profile;
				this.prefillFromProfile();
				this.setFooterHint( 'Verification successful.', false );
				this.state.loading = false;
				this.goTo( 'task_selection' );
			} catch ( error ) {
				this.state.loading = false;
				this.render();
				this.setFooterHint( error.message, true );
			}
		}

		prefillFromProfile() {
			if ( ! this.state.verifiedProfile || ! this.state.verifiedProfile.contact ) {
				return;
			}
			const contact = this.state.verifiedProfile.contact;
			this.state.contact.full_name = contact.full_name || this.state.contact.full_name;
			this.state.contact.email = contact.email || this.state.contact.email;
			this.state.contact.phone = contact.phone ? this.formatPhoneDisplay( contact.phone ) : this.state.contact.phone;
			if ( Array.isArray( this.state.verifiedProfile.addresses ) && this.state.verifiedProfile.addresses.length ) {
				const primary = this.state.verifiedProfile.addresses[0];
				this.state.address = {
					address_id: primary.id,
					address_full: primary.address_full || '',
					address_line_1: primary.address_line_1 || '',
					address_unit: primary.address_unit || '',
					city: primary.city || '',
					state: primary.state || '',
					zip_code: primary.zip_code || '',
					is_valid: true
				};
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

			return this.assistantPreparationPromise;
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

		async saveAddressAndDraft() {
			if ( ! this.stepCanContinue( 'address_details' ) ) {
				this.setFooterHint( ( config.strings.errors && config.strings.errors.addressRequired ) || 'Add the address of the job before continuing.', true );
				return;
			}
			try {
				this.state.loading = true;
				this.render();
				const response = await this.api( 'app/draft', this.buildDraftPayload( 'contact_details' ) );
				this.state.requestId = response.request_id;
				this.state.draftToken = response.draft_token;
				this.state.loading = false;
				this.goTo( 'contact_details' );
			} catch ( error ) {
				this.state.loading = false;
				this.render();
				this.setFooterHint( error.message, true );
			}
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
			this.state.contact.phone = this.formatPhoneDisplay( this.state.contact.phone );
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

			// Show a fallback notice if the embed hasn't reported ready in 15s. We render
			// the notice ABOVE the embed area so a slow-but-eventually-loading iframe still
			// works — we don't replace the container.
			if ( this.calEmbedTimeoutTimer ) {
				window.clearTimeout( this.calEmbedTimeoutTimer );
			}
			this.calEmbedTimeoutTimer = window.setTimeout( () => {
				if ( this.calEmbedMountKey === mountKey ) {
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
					const registerListener = ( api ) => {
						if ( ! api || 'function' !== typeof api ) {
							return;
						}

						api( 'on', {
							action: 'bookingSuccessfulV2',
							callback: ( event ) => {
								const detail = event && event.detail && event.detail.data ? event.detail.data : {};
								this.captureBookingSuccess( detail );
							}
						} );
						api( 'on', {
							action: 'bookingSuccessful',
							callback: ( event ) => {
								const detail = event && event.detail && event.detail.data ? event.detail.data : {};
								this.captureBookingSuccess( detail );
							}
						} );
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

					registerListener( window.Cal );
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

			const hasEnoughInformation = !! ( this.state.assistantResult && true === this.state.assistantResult.enough_information );
			const hasUserInteraction = !! ( this.state.assistantUserMessageSent || this.state.assistantThreadId );
			if ( ! hasEnoughInformation && ! hasUserInteraction ) {
				this.setFooterHint( ( config.strings.errors && config.strings.errors.assistantRequired ) || 'Please send the virtual assistant a short description of the job before continuing.', true );
				return;
			}

			try {
				this.state.loading = true;
				this.setAssistantContinueBusy( true );
				const assistantPayload = this.state.assistantResult ? Object.assign( {}, this.state.assistantResult ) : {};
				assistantPayload.enough_information = hasEnoughInformation || !! assistantPayload.enough_information;
				const payload = await this.api( 'assistant-result', {
					request_id: this.state.requestId,
					draft_token: this.state.draftToken,
					assistant_result: assistantPayload
				} );

				this.state.assistantResult = Object.assign( {}, payload.assistant_result || {}, payload.routing || {}, this.state.assistantResult || {} );
				this.state.loading = false;
				this.setAssistantContinueBusy( false );

				if ( payload && payload.unsafe_flag ) {
					this.state.unsafeReason = payload.unsafe_reason || 'Unsafe request detected.';
					this.goTo( 'unsafe' );
					return;
				}

				const booking = await this.api( 'booking-url', {
					request_id: this.state.requestId,
					draft_token: this.state.draftToken
				} );
				this.state.bookingUrl = booking.booking_url;
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
				client_type: this.state.clientType,
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
				this.assistantBridge = window.HandikChatKitBridge.mount( {
					container: container,
					requestId: this.state.requestId,
					draftToken: this.state.draftToken,
					cacheKey: 'request_' + String( this.state.requestId ),
					initialThreadId: this.state.assistantThreadId,
					startScreenGreeting: config.strings.assistantGreeting || 'Describe the job.',
					composerPlaceholder: config.strings.assistantGreeting || 'Describe the job.',
					loadingTitle: config.strings.loadingAssistant || 'Loading virtual assistant...',
					loadingSubtitle: config.strings.loadingAssistantSubtext || 'Charging the tiny robot brain for your next step.',
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
						if ( threadId ) {
							this.state.assistantUserMessageSent = true;
						}
						this.setAssistantContinueBusy( false );
					},
					onMessageActivity: ( detail ) => {
						const role = detail && ( detail.role || detail.author_role || ( detail.message && detail.message.role ) ) ? String( detail.role || detail.author_role || detail.message.role ).toLowerCase() : '';
						const messageType = detail && ( detail.type || detail.message_type || ( detail.message && detail.message.type ) ) ? String( detail.type || detail.message_type || detail.message.type ).toLowerCase() : '';
						const direction = detail && ( detail.direction || ( detail.message && detail.message.direction ) ) ? String( detail.direction || detail.message.direction ).toLowerCase() : '';
						const source = detail && ( detail.source || detail.origin || ( detail.message && ( detail.message.source || detail.message.origin ) ) ) ? String( detail.source || detail.origin || detail.message.source || detail.message.origin ).toLowerCase() : '';
						const isUserLike = 'user' === role || false !== messageType.indexOf( 'user' ) || 'outgoing' === direction || 'user' === source || 'client' === source;
						if ( isUserLike ) {
							this.state.assistantUserMessageSent = true;
							this.setAssistantContinueBusy( false );
						}
					},
					onComposerSubmit: () => {
						this.state.assistantUserMessageSent = true;
					},
					onComplete: ( normalized, payload ) => {
						this.state.assistantResult = Object.assign( {}, payload && payload.routing ? payload.routing : {}, normalized );
						this.state.assistantUserMessageSent = true;
						this.setAssistantContinueBusy( false );
						if ( payload && payload.unsafe_flag ) {
							this.state.unsafeReason = payload.unsafe_reason || 'Unsafe request detected.';
							this.goTo( 'unsafe' );
							return;
						}
					},
					onError: ( error ) => {
						this.setAssistantPreparingState( false );
						this.setAssistantNotice( error.message || 'The virtual assistant had trouble loading. Give it another moment, then send a short message about the job.', true );
					}
				} );

				return this.assistantBridge && this.assistantBridge.ready ? this.assistantBridge.ready.catch( () => {} ) : null;
			} ).finally( () => {
				this.assistantMountPromise = null;
			} );
		}

		progressMarkup() {
			if ( ! this.bootstrap || ! Array.isArray( this.bootstrap.steps ) ) {
				return '';
			}
			const visible = this.bootstrap.steps.filter( ( step ) => ! [ 'returning_verify', 'unsafe' ].includes( step ) );
			const activeIndex = Math.max( 0, visible.indexOf( this.state.step ) );
			return '<div class="handik-booking-app__progress" style="grid-template-columns: repeat(' + visible.length + ', minmax(0, 1fr));">' + visible.map( ( step, index ) => '<span class="' + ( index <= activeIndex ? 'is-active' : '' ) + '"></span>' ).join( '' ) + '</div>';
		}

		render() {
			this.root.innerHTML = '<div class="handik-booking-app__shell">' + this.stepMarkup() + '</div>';
			this.bind();
			this.renderNotifications();
			if ( this.state.selectedTasksSheetAnimate ) {
				window.setTimeout( () => {
					this.state.selectedTasksSheetAnimate = false;
				}, 2900 );
			}
			if ( 'assistant' === this.state.step ) {
				window.setTimeout( () => this.prepareAssistantStep(), 0 );
			}
			if ( 'address_details' === this.state.step ) {
				window.setTimeout( () => this.mountAddressAutocomplete(), 0 );
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
				case 'client_type':
					return this.screen(
						config.strings.clientTypeTitle || 'Who is booking today?',
						'<p class="handik-booking-app__intro">' + this.escape( config.strings.clientTypeIntro || 'Choose the option that best matches your situation.' ) + '</p>' +
				'<div class="handik-choice-grid">' +
					this.clientTypeChoiceMarkup( 'new_client', 'choose-new', config.strings.newClientLabel || 'New client' ) +
					this.clientTypeChoiceMarkup( 'returning_client', 'choose-returning', config.strings.returningClientLabel || 'Returning client' ) +
						'</div>' +
						this.footerActions( '', '', '', '', { hideBack: true, hideContinue: true } )
					);
				case 'returning_verify':
					return this.screen(
						config.strings.verifyTitle || 'Returning client verification',
						'<p class="handik-booking-app__intro">' + this.escape( config.strings.verifyIntro || 'Enter your email or phone to receive a one-time code.' ) + '</p>' +
						this.input( 'Email', 'contact.email', 'email' ) +
						this.input( 'Phone', 'contact.phone', 'tel' ) +
						'<div class="handik-inline-actions"><button data-action="send-code" class="handik-btn is-secondary">' + this.escape( config.strings.sendCode ) + '</button></div>' +
						'<label class="handik-field"><span>Verification code</span><input data-model="verificationCode" type="text" placeholder="6-digit code" value="' + this.escape( this.state.verificationCode ) + '" /></label>' +
						this.footerActions( 'back-client', 'verify-code', this.escape( config.strings.continue ), '', { continueMuted: ! this.stepCanContinue( 'returning_verify' ) } )
					);
				case 'task_selection':
					return this.screen( config.strings.taskTitle || 'What do you need help with?', this.tasksMarkup() );
				case 'address_details':
					return this.screen( config.strings.addressTitle || 'Address details', this.addressMarkup() );
				case 'photos':
					return this.screen( config.strings.photosTitle || 'Photos', this.photosMarkup() );
				case 'assistant':
					return this.screen( config.strings.assistantTitle || 'Virtual assistant', this.assistantMarkup(), 'is-wide' );
				case 'contact_details':
					return this.screen( config.strings.contactTitle || 'Contact details', this.contactMarkup() );
				case 'booking':
					return this.screen( config.strings.bookingTitle || 'Book your time slot', this.bookingMarkup() );
				case 'unsafe':
					return this.screen( config.strings.unsafeTitle || 'Unsafe request', '<p class="handik-booking-app__intro">' + this.escape( this.state.unsafeReason || config.strings.unsafeBody || 'This request needs manual review before booking.' ) + '</p><button data-action="restart" class="handik-btn is-secondary">' + this.escape( config.strings.restart || 'Start another booking' ) + '</button>' );
				default:
					return this.screen( 'Booking App', '<p>Unknown step.</p>' );
			}
		}

		screen( title, body, modifier ) {
			const overlayNeeded = this.state.loading || this.state.photoUploading || ( this.state.assistantPreparing && 'assistant' !== this.state.step );
			const overlay = overlayNeeded ? '<div class="handik-booking-app__loading-overlay" aria-live="polite">' + this.loaderMarkup( 'Loading' ) + '</div>' : '';
			const stepClass = 'handik-booking-app__screen--' + String( this.state.step || 'unknown' ).replace( /[^a-z0-9_-]/gi, '-' ).toLowerCase();
			return '<section class="handik-booking-app__screen ' + stepClass + ' ' + ( modifier || '' ) + '"><div class="handik-booking-app__screen-header"><h2>' + this.escape( title ) + '</h2></div><div class="handik-booking-app__screen-body">' + body + overlay + '</div></section>';
		}

		input( label, model, type, modifier, helpText ) {
			const value = this.getByPath( model ) || '';
			const attrs = this.inputAttrsForModel( model, type );
			return '<label class="handik-field ' + this.escape( modifier || '' ) + '"><span>' + this.escape( label ) + '</span><input type="' + this.escape( type || 'text' ) + '" data-model="' + this.escape( model ) + '" value="' + this.escape( value ) + '"' + attrs + ' /></label>' + ( helpText ? '<span class="handik-field__help">' + this.escape( helpText ) + '</span>' : '' );
		}

		inputAttrsForModel( model, type ) {
			// Pair every contact field with the right autofill / mobile keyboard hints.
			// Server normalizes phone via Handik_Booking_App_Contacts_Service::normalize_phone,
			// so any display formatting here is purely cosmetic — submission stays canonical.
			const attrs = {};
			switch ( model ) {
				case 'contact.full_name':
					attrs.autocomplete = 'name';
					attrs.autocapitalize = 'words';
					attrs.spellcheck = 'false';
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
					break;
				case 'contact.phone':
					attrs.autocomplete = 'tel';
					attrs.inputmode = 'tel';
					break;
				case 'verificationCode':
					attrs.autocomplete = 'one-time-code';
					attrs.inputmode = 'numeric';
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
						this.taskPathChoiceMarkup( 'choose-larger-scale', 'Larger-Scale Work', 'For bigger, multi-step, or consultation-first work', 'Consultation first', this.taskSelected( 'larger_scale_work' ) ) +
						this.taskPathChoiceMarkup( 'choose-specific-tasks', 'Choose Specific Tasks', 'Browse services by category and select one or more tasks', 'Price depends on task', false ) +
					'</div>' +
					this.footerActions( '', '', '', '', { hideBack: true, hideContinue: true } );
			}

			const hiddenSpecificTaskIds = [ 'general_handyman_help', 'larger_scale_work' ];
			const groups = ( ( this.bootstrap && this.bootstrap.task_catalog ) || [] ).map( ( group ) => {
				return Object.assign( {}, group, {
					tasks: ( group.tasks || [] ).filter( ( task ) => ! hiddenSpecificTaskIds.includes( task.id ) )
				} );
			} ).filter( ( group ) => group.tasks.length );
			return '<p class="handik-booking-app__intro">' + this.escape( 'Tap one or more services to select or remove them.' ) + '</p><div class="handik-task-groups">' +
				groups.map( ( group ) => '<div class="handik-task-group"><h3>' + this.escape( group.group ) + '</h3><div class="handik-task-grid">' +
					group.tasks.map( ( task ) => '<button type="button" class="handik-task ' + ( this.taskSelected( task.id ) ? 'is-selected' : '' ) + '" data-task-id="' + this.escape( task.id ) + '">' + this.escape( task.label ) + '</button>' ).join( '' ) +
				'</div></div>' ).join( '' ) +
				this.footerActions( '', 'tasks-next', this.escape( config.strings.continue ), '', { continueMuted: ! this.stepCanContinue( 'task_selection' ), hideBack: true } ) +
				this.selectedTasksSheetMarkup() +
			'</div>';
		}

		addressMarkup() {
			const addressOptions = this.state.verifiedProfile && Array.isArray( this.state.verifiedProfile.addresses ) ? this.state.verifiedProfile.addresses : [];
			const isReturningProfile = !! ( this.state.verifiedProfile && this.state.verifiedProfile.contact );
			let savedAddressMarkup = '';
			if ( addressOptions.length ) {
				savedAddressMarkup = '<label class="handik-field"><span>' + this.escape( config.strings.savedAddressLabel || 'Saved address' ) + '</span><select id="handik-saved-address" autocomplete="off"><option value="">' + this.escape( config.strings.savedAddressPlaceholder || 'Choose saved address' ) + '</option>' + addressOptions.map( ( item ) => '<option value="' + item.id + '">' + this.escape( item.address_full ) + '</option>' ).join( '' ) + '</select></label>';
			} else if ( isReturningProfile ) {
				savedAddressMarkup = '<p class="handik-field__help handik-field__help--empty" role="status">' + this.escape( 'No saved addresses yet — enter the address below.' ) + '</p>';
			}

			return (
				savedAddressMarkup +
				'<label class="handik-field handik-field--address' + ( this.state.address.is_valid || ! this.state.address.address_full ? '' : ' is-invalid' ) + '"><span>' + this.escape( config.strings.addressLabel || 'Address of the job' ) + '</span><input id="handik-job-address" type="text" data-model="address.address_full" autocomplete="street-address" placeholder="' + this.escape( config.strings.addressPlaceholder || 'Start typing the address of the job' ) + '" value="' + this.escape( this.state.address.address_full || '' ) + '" />' +
					( this.state.address.address_full && ! this.state.address.is_valid ? '<span class="handik-field__error" role="alert">' + this.escape( ( config.strings.errors && config.strings.errors.addressRequired ) || 'Choose a valid address from the suggestions to continue.' ) + '</span>' : '' ) +
				'</label>' +
				'<label class="handik-field"><span>' + this.escape( config.strings.unitLabel || 'Unit or apartment (optional)' ) + '</span><input type="text" data-model="address.address_unit" autocomplete="address-line2" value="' + this.escape( this.state.address.address_unit || '' ) + '" /></label>' +
				this.footerActions( 'back-address', 'address-next', this.escape( config.strings.continue ), '', { continueMuted: ! this.stepCanContinue( 'address_details' ) } )
			);
		}

		photosMarkup() {
			const ctaLabel = ( config.strings.photosCta || 'Tap to add photos' ) + ' (up to 10 MB each)';
			const photosMarkup = this.state.photos.length
				? '<ul class="handik-photo-list">' + this.state.photos.map( ( photo, index ) => {
					const photoId = photo.id || photo.attachment_id || photo.url || ( 'photo-' + index );
					const photoName = photo.name || photo.url || 'photo';
					return '<li class="handik-photo-list__item"><span class="handik-photo-list__name">' + this.escape( photoName ) + '</span>' +
						'<button type="button" class="handik-photo-list__remove" data-action="remove-photo" data-photo-id="' + this.escape( String( photoId ) ) + '" aria-label="' + this.escape( 'Remove ' + photoName ) + '">&times;</button>' +
						'</li>';
				} ).join( '' ) + '</ul>'
				: '<div class="handik-photo-list is-empty"><span>' + this.escape( config.strings.photosEmpty || 'No photos added yet' ) + '</span></div>';
			return '<p class="handik-booking-app__intro">' + this.escape( config.strings.photosIntro || 'Photos really help us understand the job faster, but you can continue without them if needed.' ) + '</p>' +
				'<label class="handik-field"><span>' + this.escape( config.strings.photosLabel || 'Photos' ) + '</span><span class="handik-field__help">' + this.escape( config.strings.photosHelp || 'Add a few clear photos so we can understand the job faster.' ) + '</span><input type="file" id="handik-photo-input" class="handik-photo-input" multiple accept="image/*" /><button type="button" class="handik-photo-dropzone" data-action="choose-photos"><span class="handik-photo-dropzone__icon" aria-hidden="true"></span><span>' + this.escape( ctaLabel ) + '</span>' + ( this.state.photoUploading ? '<span>' + this.escape( config.strings.uploading || 'Loading...' ) + '</span><span class="handik-inline-spinner" aria-hidden="true"></span>' : '' ) + '</button>' + photosMarkup + '</label>' +
				this.footerActions( 'back-photos', 'photos-next', this.escape( config.strings.continue ), this.escape( config.strings.back ), { continueMuted: ! this.stepCanContinue( 'photos' ) } );
		}

		assistantMarkup() {
			const skeleton = '<div class="handik-skeleton handik-skeleton--assistant" aria-hidden="true"><div class="handik-skeleton__bar"></div><div class="handik-skeleton__bar handik-skeleton__bar--short"></div><div class="handik-skeleton__bar"></div></div>';
			return '<p class="handik-booking-app__intro">' + this.escape( config.strings.assistantIntro || 'This AI assistant helps you understand rough cost, timing, materials, and what to expect, while helping us collect the details needed to prepare for the job properly.' ) + '</p><div class="handik-assistant-layout"><div class="handik-assistant-panel"><div class="handik-booking-app__assistant-host">' + skeleton + '</div>' + this.footerActions( '', 'assistant-next', this.escape( config.strings.assistantContinue || 'Book a time' ), '', { continueMuted: false, hideBack: true } ) + '</div></div>';
		}

		contactMarkup() {
			if ( this.state.verifiedProfile && this.state.verifiedProfile.contact && ! this.state.contact.full_name ) {
				this.prefillFromProfile();
			}
			return '<p class="handik-booking-app__intro">' + this.escape( config.strings.contactIntro || 'This is the last step where you can change the booking details before the AI review starts.' ) + '</p>' +
				this.input( 'Full name', 'contact.full_name', 'text', this.isFieldInvalid( 'full_name' ) ? 'is-invalid' : '', '' ) +
				'<div class="handik-grid-2">' +
				this.input( 'Email', 'contact.email', 'email', this.isFieldInvalid( 'email' ) ? 'is-invalid' : '', '' ) +
				this.input( 'Phone', 'contact.phone', 'tel', this.isFieldInvalid( 'phone' ) ? 'is-invalid' : '', '' ) +
				'</div>' +
				this.footerActions( 'back-contact', 'contact-next', this.escape( config.strings.contactContinue || 'Continue to Assistant' ), '', { continueMuted: ! this.stepCanContinue( 'contact_details' ) } );
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
			const backText = backLabel || this.escape( config.strings.back );
			const backClass = ( settings.backIsUtility ? 'handik-btn is-secondary' : 'handik-btn is-secondary is-back' ) + ( settings.backMuted ? ' is-disabled' : '' );
			const backInner = settings.backIsUtility ? '<span class="handik-btn__label">' + backText + '</span>' : '<span class="handik-btn__icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M19 11H7.83l4.88-4.88L11.29 4.7 4 12l7.29 7.3 1.42-1.42L7.83 13H19v-2z"></path></svg></span><span class="handik-btn__label">' + backText + '</span>';
			const continueClass = 'handik-btn ' + ( settings.continueMuted ? 'is-pending' : 'is-primary' ) + ' is-continue';
			const backButton = settings.hideBack ? '' : '<button data-action="' + this.escape( backAction ) + '" class="' + backClass + '"' + ( settings.backMuted ? ' aria-disabled="true"' : '' ) + '>' + backInner + '</button>';
			const utilityButton = settings.utilityAction ? '<button data-action="' + this.escape( settings.utilityAction ) + '" class="handik-btn is-text">' + this.escape( settings.utilityLabel || '' ) + '</button>' : '';
			const continueButton = settings.hideContinue ? '' : '<div class="handik-footer-actions__continue">' + utilityButton + '<button data-action="' + this.escape( continueAction ) + '" class="' + continueClass + '" aria-disabled="' + ( settings.continueMuted ? 'true' : 'false' ) + '">' + continueLabel + '</button></div>';
			const actions = backButton || continueButton ? '<div class="handik-footer-actions is-docked' + ( settings.hideBack || settings.hideContinue ? ' is-single' : '' ) + '">' + backButton + continueButton + '</div>' : '';
			return '<div class="handik-footer-wrap">' + actions + '<div class="handik-footer-progress">' + this.progressMarkup() + '</div></div>';
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
					input.value = this.state.address.address_full || input.value;
					this.render();
				} );

				input.setAttribute( 'data-google-mounted', '1' );
			} catch ( error ) {
				this.googleMapsPromise = Promise.resolve( null );
				console.error( '[HandikBookingApp] Google Maps autocomplete failed.', error );
			}
		}

		bind() {
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
					let value = 'checkbox' === input.type ? input.checked : input.value;
					const model = input.getAttribute( 'data-model' );
					if ( 'contact.full_name' === model ) {
						value = String( value || '' ).replace( /[^\p{L}\s'-]/gu, '' ).replace( /\s{2,}/g, ' ' );
						input.value = value;
						this.state.touched.full_name = true;
					}
					if ( 'contact.phone' === model ) {
						// Sanitize allowed characters first.
						const allowed = String( value || '' ).replace( /[^0-9+\s()-]/g, '' );
						// Apply mask while preserving caret position based on digit count.
						const selectionStart = input.selectionStart || allowed.length;
						const digitsBefore = allowed.slice( 0, selectionStart ).replace( /\D/g, '' ).length;
						const formatted = this.formatPhoneDisplay( allowed );
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
							caret = formatted.startsWith( '+' ) ? 1 : 0;
						}
						try {
							input.setSelectionRange( caret, caret );
						} catch ( e ) {
							// Some inputs (number, email) reject setSelectionRange — ignore.
						}
						value = formatted;
						this.state.touched.phone = true;
					}
					if ( 'contact.email' === model ) {
						this.state.touched.email = true;
					}
					if ( 'address.address_full' === model ) {
						this.state.address.is_valid = false;
						this.state.address.address_id = 0;
					}
					this.setByPath( model, value );
					this.refreshFieldValidation( model, input );
					this.refreshCurrentStepActions();
					this._scheduleDraftSave();
				} );

				const model = input.getAttribute( 'data-model' );
				if ( 'contact.phone' === model ) {
					input.addEventListener( 'blur', () => {
						// On blur we still re-apply the mask in case the user pasted raw digits.
						const normalized = this.formatPhoneDisplay( input.value );
						this.state.contact.phone = normalized;
						input.value = normalized;
						this.state.touched.phone = true;
						this.refreshFieldValidation( model, input );
					} );
				}
				if ( 'contact.email' === model ) {
					input.addEventListener( 'blur', () => {
						this.state.touched.email = true;
						this.refreshFieldValidation( model, input );
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
							address_full: chosen.address_full || '',
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
