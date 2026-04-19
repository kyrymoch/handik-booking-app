( function( window, document ) {
	'use strict';

	const config = window.HandikBookingAppConfig || {};
	const GOOGLE_SCRIPT_ID = 'handik-google-maps-places';
	const CAL_EMBED_SCRIPT_ID = 'handik-cal-embed-script';

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
			this.notificationTimers = new Map();
			this.assistantBridge = null;
			this.state = {
				step: 'client_type',
				clientType: '',
				verifiedProfile: null,
				requestId: 0,
				draftToken: '',
				selectedTasks: [],
				isProject: false,
				jobShape: '',
				address: { address_id: 0, address_full: '', address_line_1: '', address_unit: '', city: '', state: '', zip_code: '' },
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
				appSessionKey: 'app_' + Math.random().toString( 36 ).slice( 2 ),
				message: '',
				footerHint: '',
				footerHintError: false,
				lastAssistantNotice: '',
				loading: false,
				photoUploading: false,
				bookingOpened: false,
				notifications: []
			};
		}

		async init() {
			this.renderLoading();
			try {
				this.bootstrap = await this.api( 'app/bootstrap', {}, 'GET' );
				if ( this.bootstrap.verified_profile ) {
					this.state.verifiedProfile = this.bootstrap.verified_profile;
				}
				this.render();
			} catch ( error ) {
				this.root.innerHTML = '<div class="handik-booking-app__shell"><div class="handik-booking-app__alert is-error">' + this.escape( error.message ) + '</div></div>';
			}
		}

		renderLoading() {
			this.root.innerHTML = '<div class="handik-booking-app__shell"><div class="handik-booking-app__loading"><div class="handik-loading-visual handik-loading-visual--roller" aria-hidden="true"><span class="handik-roller-track"></span><span class="handik-roller-head"></span><span class="handik-roller-arm"></span><span class="handik-roller-handle"></span></div><strong>' + this.escape( config.strings.loading || 'Loading...' ) + '</strong><span class="handik-booking-app__loading-subtitle">' + this.escape( config.strings.loadingSubtext || 'Our toolbox is waking up and the coffee is still hot.' ) + '</span></div></div>';
		}

		async api( path, data, method, formData ) {
			const options = {
				method: method || 'POST',
				credentials: 'same-origin',
				headers: {}
			};

			if ( formData ) {
				options.body = formData;
			} else if ( 'GET' !== options.method ) {
				options.headers['Content-Type'] = 'application/json';
				options.body = JSON.stringify( data || {} );
			}

			const response = await window.fetch( config.restBase + path, options );
			const payload = await response.json().catch( function() {
				return {};
			} );
			if ( ! response.ok ) {
				throw new Error( payload.message || payload.error || 'Request failed' );
			}
			return payload;
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
				this.notify( 'info', config.strings.infoTitle || 'Notice', message, 4200 );
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
				meta: Array.isArray( settings.meta ) ? settings.meta : []
			};
			this.state.notifications = this.state.notifications.concat( item ).slice( -4 );
			this.renderNotifications();
			window.requestAnimationFrame( () => {
				this.updateNotification( item.id, { visible: true } );
				this.resumeNotification( item.id );
			} );
		}

		notifyTask( task ) {
			if ( ! task ) {
				return;
			}

			const meta = [
				{ label: config.strings.taskNoticeBadge || 'Task details', tone: 'task' }
			];

			if ( task.rate_label ) {
				meta.push( { label: task.rate_label, tone: 'rate' } );
			}

			this.notify( 'task', task.label || ( config.strings.taskTitle || 'Task' ), task.description || '', 5200, { meta: meta } );
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
				startedAt: 0
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
				remaining: remaining
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
				case 'success':
					return '&check;';
				case 'error':
					return '&times;';
				case 'warning':
					return '&#9888;';
				case 'task':
					return '&#128736;';
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
			const progressStart = Math.max( 0, Math.min( 1, duration / Math.max( duration, item.duration || 3200 ) ) );

			return '<div class="' + classes + '" style="--handik-toast-duration:' + duration + 'ms;--handik-toast-progress-start:' + progressStart + ';" data-notification-id="' + this.escape( item.id ) + '">' +
					'<div class="handik-toast__icon" aria-hidden="true">' + this.notificationIcon( item.type ) + '</div>' +
					'<div class="handik-toast__content">' +
						this.renderNotificationMeta( item ) +
						'<div class="handik-toast__body">' +
							'<strong>' + this.escape( item.title ) + '</strong>' +
							'<span>' + this.escape( item.message ) + '</span>' +
						'</div>' +
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
		}

		showStepWarning( step, fallbackMessage ) {
			this.notify(
				'warning',
				config.strings.warningTitle || 'Check this step',
				this.stepBlockMessage( step ) || fallbackMessage || '',
				4200
			);
		}

		setFooterHint( message, isError ) {
			if ( message ) {
				this.notify( isError ? 'warning' : 'info', isError ? ( config.strings.warningTitle || 'Check this step' ) : ( config.strings.infoTitle || 'Notice' ), message, 4200 );
			}
			this.state.footerHint = '';
			this.state.footerHintError = false;
		}

		clearFooterHint() {
			this.state.footerHint = '';
			this.state.footerHintError = false;
		}

		setAssistantNotice( message, isError ) {
			const next = String( message || '' ) + '|' + ( isError ? '1' : '0' );
			if ( ! message || this.state.lastAssistantNotice === next ) {
				return;
			}
			this.state.lastAssistantNotice = next;
			this.notify( isError ? 'warning' : 'info', config.strings.assistantTitle || 'Virtual assistant', message, isError ? 5200 : 4200 );
		}

		setAssistantContinueBusy( isBusy ) {
			const button = this.root.querySelector( '[data-action="assistant-next"]' );
			if ( button ) {
				button.disabled = !! isBusy;
				button.textContent = isBusy ? 'Saving...' : ( config.strings.continue || 'Continue' );
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
					return !! this.state.address.address_full && ! this.state.photoUploading;
				case 'photos':
					return ! this.state.photoUploading;
				case 'assistant':
					return true;
				case 'contact_details':
					return !! ( this.state.contact.full_name && this.state.contact.email );
				default:
					return true;
			}
		}

		stepBlockMessage( step ) {
			const errors = config.strings.errors || {};
			switch ( step ) {
				case 'client_type':
					return errors.pickClientType || 'Choose whether you are a new client or a returning client to continue.';
				case 'returning_verify':
					return errors.invalidCode || 'Enter your code and verify to continue.';
				case 'task_selection':
					return errors.selectTask || 'Select at least one task or mark this as a project.';
				case 'address_details':
					return errors.addressRequired || 'Add the address of the job before continuing.';
				case 'photos':
					return '';
				case 'assistant':
					return errors.assistantRequired || 'Please send the virtual assistant a short description of the job before continuing.';
				case 'contact_details':
					return errors.nameEmailRequired || 'Name and email are required before you can continue.';
				default:
					return '';
			}
		}

		clientTypeChoiceMarkup( type, action, label ) {
			const isSelected = type === this.state.clientType;
			return '<div class="handik-choice-wrap"><button data-action="' + this.escape( action ) + '" class="handik-choice ' + ( isSelected ? 'is-selected' : '' ) + '"><span class="handik-choice__title">' + this.escape( label ) + '</span></button></div>';
		}

		goTo( step ) {
			if ( 'booking' === this.state.step && 'booking' !== step ) {
				this.stopBookingStatusPolling();
			}

			this.state.step = step;
			this.state.footerHint = '';
			this.state.footerHintError = false;
			this.render();
			if ( 'assistant' === step ) {
				window.setTimeout( () => this.mountAssistant(), 0 );
			}
			if ( 'address_details' === step ) {
				window.setTimeout( () => this.mountAddressAutocomplete(), 0 );
			}
			if ( 'booking' === step ) {
				window.setTimeout( () => this.startBookingStatusPolling(), 0 );
			}
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

		taskSelected( id ) {
			return this.state.selectedTasks.includes( id );
		}

		toggleTask( id ) {
			if ( this.taskSelected( id ) ) {
				this.state.selectedTasks = this.state.selectedTasks.filter( ( taskId ) => taskId !== id );
			} else {
				this.state.selectedTasks = this.state.selectedTasks.concat( id );
				const task = this.findTask( id );
				if ( task ) {
					this.notifyTask( task );
				}
			}
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

		async handleAction( action ) {
			this.clearFooterHint();
			switch ( action ) {
				case 'start':
					this.goTo( 'client_type' );
					break;
				case 'choose-new':
					this.state.clientType = 'new_client';
					this.notify(
						'info',
						config.strings.newClientTooltipTitle || 'New client',
						config.strings.newClientTooltipText || 'Choose this if this is your first time booking with Handik or you have not used our booking flow before.'
					);
					this.render();
					break;
				case 'choose-returning':
					this.state.clientType = 'returning_client';
					this.notify(
						'info',
						config.strings.returningClientTooltipTitle || 'Returning client',
						config.strings.returningClientTooltipText || 'Choose this if you have booked with Handik before and want to reuse your saved details.'
					);
					this.render();
					break;
				case 'client-type-next':
					if ( ! this.state.clientType ) {
						this.setFooterHint( this.stepBlockMessage( 'client_type' ), true );
						return;
					}
					this.goTo( 'returning_client' === this.state.clientType ? 'returning_verify' : 'task_selection' );
					break;
				case 'send-code':
					await this.sendCode();
					break;
				case 'verify-code':
					await this.verifyCode();
					break;
				case 'tasks-next':
					if ( ! this.stepCanContinue( 'task_selection' ) ) {
						this.setFooterHint( this.stepBlockMessage( 'task_selection' ), true );
						return;
					}
					this.state.jobShape = this.state.isProject ? 'project' : ( this.state.selectedTasks.length > 1 ? 'multiple_tasks' : 'single_task' );
					this.goTo( 'address_details' );
					break;
				case 'address-next':
					if ( ! this.stepCanContinue( 'address_details' ) ) {
						this.setFooterHint( this.stepBlockMessage( 'address_details' ), true );
						return;
					}
					this.goTo( 'photos' );
					break;
				case 'photos-next':
				case 'skip-photos':
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
				case 'restart':
					this.stopBookingStatusPolling();
					if ( window.HandikChatKitBridge && typeof window.HandikChatKitBridge.reset === 'function' && this.state.requestId ) {
						window.HandikChatKitBridge.reset( 'request_' + String( this.state.requestId ) );
					}
					this.state = Object.assign( this.state, {
						step: 'client_type',
						clientType: '',
						requestId: 0,
						draftToken: '',
						selectedTasks: [],
						isProject: false,
						jobShape: '',
						address: { address_id: 0, address_full: '', address_line_1: '', address_unit: '', city: '', state: '', zip_code: '' },
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
					this.goTo( 'task_selection' );
					break;
				case 'back-address':
					this.goTo( 'address_details' );
					break;
				case 'back-photos':
					this.goTo( 'photos' );
					break;
				case 'back-contact':
					this.goTo( 'assistant' );
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
					phone: this.state.contact.phone,
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
					phone: this.state.contact.phone,
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
			this.state.contact.phone = contact.phone || this.state.contact.phone;
			if ( Array.isArray( this.state.verifiedProfile.addresses ) && this.state.verifiedProfile.addresses.length ) {
				const primary = this.state.verifiedProfile.addresses[0];
				this.state.address = {
					address_id: primary.id,
					address_full: primary.address_full || '',
					address_line_1: primary.address_line_1 || '',
					address_unit: primary.address_unit || '',
					city: primary.city || '',
					state: primary.state || '',
					zip_code: primary.zip_code || ''
				};
			}
		}

		async uploadFiles( files ) {
			if ( ! files || ! files.length ) {
				return;
			}
			await this.ensureDraftRequest( 'photos' );
			for ( const file of files ) {
				const formData = new window.FormData();
				formData.append( 'file', file );
				formData.append( 'request_id', String( this.state.requestId || 0 ) );
				formData.append( 'draft_token', this.state.draftToken || '' );
				formData.append( 'contact_id', String( ( this.state.verifiedProfile && this.state.verifiedProfile.contact && this.state.verifiedProfile.contact.id ) || 0 ) );
				formData.append( 'app_session_key', this.state.appSessionKey );
				const uploaded = await this.api( 'app/upload', {}, 'POST', formData );
				this.state.photos.push( uploaded );
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

		async saveAddressAndDraft() {
			if ( ! this.state.address.address_full ) {
				this.setFooterHint( ( config.strings.errors && config.strings.errors.addressRequired ) || 'Add the address of the job before continuing.', true );
				return;
			}
			try {
				this.state.loading = true;
				this.render();
				const response = await this.api( 'app/draft', this.buildDraftPayload( 'assistant' ) );
				this.state.requestId = response.request_id;
				this.state.draftToken = response.draft_token;
				this.state.loading = false;
				this.goTo( 'assistant' );
			} catch ( error ) {
				this.state.loading = false;
				this.render();
				this.setFooterHint( error.message, true );
			}
		}

		async saveContactAndPrepareBooking() {
			if ( ! this.state.contact.full_name || ! this.state.contact.email ) {
				this.setFooterHint( ( config.strings.errors && config.strings.errors.nameEmailRequired ) || 'Name and email are required before you can continue.', true );
				return;
			}
			this.state.contact.phone = this.normalizePhone( this.state.contact.phone );
			try {
				this.state.loading = true;
				this.render();
				const draft = await this.api( 'app/draft', this.buildDraftPayload( 'booking' ) );
				this.state.requestId = draft.request_id;
				this.state.draftToken = draft.draft_token;
				const booking = await this.api( 'booking-url', {
					request_id: this.state.requestId,
					draft_token: this.state.draftToken
				} );
				this.state.bookingUrl = booking.booking_url;
				this.state.bookingStatus = 'booking_pending';
				this.state.bookingStatusMessage = config.strings.bookingWaiting || 'Stay on this screen while we wait for Cal.com to confirm the booking.';
				this.state.loading = false;
				this.goTo( 'booking' );
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
					this.state.bookingStatusMessage = config.strings.bookingConfirmed || 'Booking confirmed. Finishing your request...';
					this.stopBookingStatusPolling();
					this.notify( 'success', config.strings.bookingTitle || 'Book your time slot', this.state.bookingStatusMessage, 3600 );
					this.goTo( 'success' );
					return true;
				}

				if ( 'cancelled' === this.state.bookingStatus ) {
					this.setBookingStatusMessage( config.strings.bookingCancelled || 'This booking was cancelled. You can book another slot below.', true );
				} else if ( payload.cal_booking_id ) {
					this.setBookingStatusMessage( config.strings.bookingWaiting || 'Stay on this screen while we wait for Cal.com to confirm the booking.', false );
				} else if ( showMessage ) {
					const message = config.strings.bookingWaiting || 'Finish the booking in the calendar above. This page will update automatically once Cal.com confirms the slot.';
					this.setBookingStatusMessage( message, false );
					this.notify( 'info', config.strings.bookingTitle || 'Book your time slot', message, 3600 );
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
				this.state.bookingStatusMessage = config.strings.bookingConfirmed || 'Booking confirmed. Finishing your request...';
				this.stopBookingStatusPolling();
				this.notify( 'success', config.strings.bookingTitle || 'Book your time slot', this.state.bookingStatusMessage, 3600 );
				this.goTo( 'success' );
			} catch ( error ) {
				this.setBookingStatusMessage( error.message || 'We could not save the booking yet.', true );
				this.notify( 'error', config.strings.bookingTitle || 'Book your time slot', error.message || 'We could not save the booking yet.' );
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

				this.goTo( 'contact_details' );
			} catch ( error ) {
				this.state.loading = false;
				this.setAssistantContinueBusy( false );
				this.setAssistantNotice( error.message || 'We could not save the assistant step yet.', true );
				this.notify( 'error', 'Assistant step issue', error.message || 'We could not save the assistant step yet.' );
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
				phone: this.state.contact.phone,
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

			this.assistantBridge = window.HandikChatKitBridge.mount( {
				container: container,
				requestId: this.state.requestId,
				draftToken: this.state.draftToken,
				cacheKey: 'request_' + String( this.state.requestId ),
				initialThreadId: this.state.assistantThreadId,
				startScreenGreeting: config.strings.assistantGreeting || 'Describe the task and I will help estimate time, materials, and the next step.',
				composerPlaceholder: config.strings.assistantGreeting || 'Describe the task and I will help estimate time, materials, and the next step.',
				loadingTitle: config.strings.loadingAssistant || 'Loading virtual assistant...',
				loadingSubtitle: config.strings.loadingAssistantSubtext || 'Charging the tiny robot brain for your next step.',
				endpoints: {
					createSession: config.restBase + 'chatkit-session',
					saveAssistantResult: config.restBase + 'assistant-result',
					associateThread: config.restBase + 'chatkit-thread',
					clientLog: config.restBase + 'client-log'
				},
				onSessionReady: () => {
					this.setAssistantNotice( config.strings.assistantHelper || 'Describe the job in chat, ask questions if needed, then tap Continue when you are ready.', false );
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
						this.setAssistantNotice( config.strings.assistantHelper || 'Describe the job in chat, ask questions if needed, then tap Continue when you are ready.', false );
						this.setAssistantContinueBusy( false );
					}
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
					this.setAssistantNotice( config.strings.assistantReadyNotice || 'The virtual assistant has enough information. Continue when you are ready.', false );
				},
				onError: ( error ) => {
					this.setAssistantNotice( error.message || 'The virtual assistant had trouble loading. Give it another moment, then send a short message about the job.', true );
				}
			} );
		}

		progressMarkup() {
			if ( ! this.bootstrap || ! Array.isArray( this.bootstrap.steps ) ) {
				return '';
			}
			const visible = this.bootstrap.steps.filter( ( step ) => ! [ 'welcome', 'returning_verify', 'unsafe' ].includes( step ) );
			const activeIndex = Math.max( 0, visible.indexOf( this.state.step ) );
			return '<div class="handik-booking-app__progress" style="grid-template-columns: repeat(' + visible.length + ', minmax(0, 1fr));">' + visible.map( ( step, index ) => '<span class="' + ( index <= activeIndex ? 'is-active' : '' ) + '"></span>' ).join( '' ) + '</div>';
		}

		render() {
			this.root.innerHTML = '<div class="handik-booking-app__shell">' + this.progressMarkup() + this.stepMarkup() + '</div>';
			this.bind();
			this.renderNotifications();
			if ( 'assistant' === this.state.step ) {
				window.setTimeout( () => this.mountAssistant(), 0 );
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
						this.footerActions( 'back-start', 'client-type-next', this.escape( config.strings.continue ), '', { continueMuted: ! this.stepCanContinue( 'client_type' ), hideBack: true } )
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
				case 'success':
					return this.screen( config.strings.successTitle || 'Success', '<p class="handik-booking-app__intro">' + this.escape( config.strings.successBody || 'Your booking has been confirmed and saved.' ) + '</p><button data-action="restart" class="handik-btn is-secondary">' + this.escape( config.strings.restart || 'Start another booking' ) + '</button>' );
				case 'unsafe':
					return this.screen( config.strings.unsafeTitle || 'Unsafe request', '<p class="handik-booking-app__intro">' + this.escape( this.state.unsafeReason || config.strings.unsafeBody || 'This request needs manual review before booking.' ) + '</p><button data-action="restart" class="handik-btn is-secondary">' + this.escape( config.strings.restart || 'Start another booking' ) + '</button>' );
				default:
					return this.screen( 'Booking App', '<p>Unknown step.</p>' );
			}
		}

		screen( title, body, modifier ) {
			return '<section class="handik-booking-app__screen ' + ( modifier || '' ) + '"><div class="handik-booking-app__screen-header"><h2>' + this.escape( title ) + '</h2></div><div class="handik-booking-app__screen-body">' + body + '</div></section>';
		}

		input( label, model, type ) {
			const value = this.getByPath( model ) || '';
			return '<label class="handik-field"><span>' + this.escape( label ) + '</span><input type="' + this.escape( type || 'text' ) + '" data-model="' + this.escape( model ) + '" value="' + this.escape( value ) + '" /></label>';
		}

		getByPath( path ) {
			return path.split( '.' ).reduce( ( current, key ) => current && current[ key ], this.state );
		}

		tasksMarkup() {
			const groups = ( this.bootstrap && this.bootstrap.task_catalog ) || [];
			return '<p class="handik-booking-app__intro">' + this.escape( config.strings.taskIntro || 'Choose one or more services so we can route your booking correctly.' ) + '</p><div class="handik-task-groups">' +
				groups.map( ( group ) => '<div class="handik-task-group"><h3>' + this.escape( group.group ) + '</h3><div class="handik-task-grid">' +
					group.tasks.map( ( task ) => '<button type="button" class="handik-task ' + ( this.taskSelected( task.id ) ? 'is-selected' : '' ) + '" data-task-id="' + this.escape( task.id ) + '">' + this.escape( task.label ) + '</button>' ).join( '' ) +
				'</div></div>' ).join( '' ) +
				'<label class="handik-check"><input type="checkbox" data-model="isProject" ' + ( this.state.isProject ? 'checked' : '' ) + ' /> ' + this.escape( config.strings.projectLabel || 'Project / Large Job' ) + '</label>' +
				this.footerActions( this.state.clientType === 'returning_client' ? 'back-returning' : 'back-client', 'tasks-next', this.escape( config.strings.continue ), '', { continueMuted: ! this.stepCanContinue( 'task_selection' ) } ) +
			'</div>';
		}

		addressMarkup() {
			const addressOptions = this.state.verifiedProfile && Array.isArray( this.state.verifiedProfile.addresses ) ? this.state.verifiedProfile.addresses : [];
			return (
				( addressOptions.length ? '<label class="handik-field"><span>' + this.escape( config.strings.savedAddressLabel || 'Saved address' ) + '</span><select id="handik-saved-address"><option value="">' + this.escape( config.strings.savedAddressPlaceholder || 'Choose saved address' ) + '</option>' + addressOptions.map( ( item ) => '<option value="' + item.id + '">' + this.escape( item.address_full ) + '</option>' ).join( '' ) + '</select></label>' : '' ) +
				'<label class="handik-field handik-field--address"><span>' + this.escape( config.strings.addressLabel || 'Address of the job' ) + '</span><input id="handik-job-address" type="text" data-model="address.address_full" placeholder="' + this.escape( config.strings.addressPlaceholder || 'Start typing the address of the job' ) + '" value="' + this.escape( this.state.address.address_full || '' ) + '" /></label>' +
				'<label class="handik-field"><span>' + this.escape( config.strings.unitLabel || 'Unit or apartment (optional)' ) + '</span><input type="text" data-model="address.address_unit" value="' + this.escape( this.state.address.address_unit || '' ) + '" /></label>' +
				this.footerActions( 'back-tasks', 'address-next', this.escape( config.strings.continue ), '', { continueMuted: ! this.stepCanContinue( 'address_details' ) } )
			);
		}

		photosMarkup() {
			const photosMarkup = this.state.photos.length
				? '<div class="handik-photo-list">' + this.state.photos.map( ( photo ) => '<span>' + this.escape( photo.name || photo.url || 'photo' ) + '</span>' ).join( '' ) + '</div>'
				: '<div class="handik-photo-list is-empty"><span>' + this.escape( config.strings.photosEmpty || 'No photos added yet' ) + '</span></div>';
			return '<p class="handik-booking-app__intro">' + this.escape( config.strings.photosIntro || 'Photos really help us understand the job faster, but you can skip this step if you do not have any.' ) + '</p>' +
				'<label class="handik-field"><span>' + this.escape( config.strings.photosLabel || 'Photos' ) + '</span><span class="handik-field__help">' + this.escape( config.strings.photosHelp || 'Add a few clear photos so we can understand the job faster.' ) + '</span><input type="file" id="handik-photo-input" class="handik-photo-input" multiple accept="image/*" /><button type="button" class="handik-photo-dropzone" data-action="choose-photos"><span class="handik-photo-dropzone__icon" aria-hidden="true"></span><strong>' + this.escape( config.strings.photosCta || 'Tap to add photos' ) + '</strong><span>' + this.escape( this.state.photoUploading ? ( config.strings.uploading || 'Uploading your photos...' ) : ( config.strings.photosHelp || 'Add a few clear photos so we can understand the job faster.' ) ) + '</span>' + ( this.state.photoUploading ? '<span class="handik-inline-spinner" aria-hidden="true"></span>' : '' ) + '</button>' + photosMarkup + '</label>' +
				this.footerActions( 'back-address', 'photos-next', this.state.loading ? 'Saving...' : this.escape( config.strings.continue ), this.escape( config.strings.back ), { continueMuted: ! this.stepCanContinue( 'photos' ), utilityLabel: this.escape( config.strings.skipPhotos || 'Skip photos' ), utilityAction: 'skip-photos' } );
		}

		assistantMarkup() {
			return '<div class="handik-assistant-layout"><div class="handik-assistant-panel"><div class="handik-booking-app__assistant-host"></div>' + this.footerActions( 'back-photos', 'assistant-next', this.state.loading ? 'Saving...' : this.escape( config.strings.continue ), '', { continueMuted: false } ) + '</div></div>';
		}

		contactMarkup() {
			if ( this.state.verifiedProfile && this.state.verifiedProfile.contact && ! this.state.contact.full_name ) {
				this.prefillFromProfile();
			}
			return this.input( 'Full name', 'contact.full_name', 'text' ) +
				'<div class="handik-grid-2">' +
				this.input( 'Email', 'contact.email', 'email' ) +
				this.input( 'Phone', 'contact.phone', 'tel' ) +
				'</div>' +
				this.footerActions( 'back-contact', 'contact-next', this.state.loading ? 'Preparing...' : this.escape( config.strings.continue ), '', { continueMuted: ! this.stepCanContinue( 'contact_details' ) } );
		}

		bookingMarkup() {
			return '<div class="handik-booking-app__booking-embed"></div>' +
				this.footerActions( 'open-booking', 'refresh-booking-status', this.escape( config.strings.completeBooking ), this.escape( config.strings.openBooking ), { backIsUtility: true } );
		}

		footerActions( backAction, continueAction, continueLabel, backLabel, options ) {
			const settings = options || {};
			const backText = backLabel || this.escape( config.strings.back );
			const backClass = ( settings.backIsUtility ? 'handik-btn is-secondary' : 'handik-btn is-secondary is-back' ) + ( settings.backMuted ? ' is-disabled' : '' );
			const backInner = settings.backIsUtility ? '<span class="handik-btn__label">' + backText + '</span>' : '<span class="handik-btn__icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M19 11H7.83l4.88-4.88L11.29 4.7 4 12l7.29 7.3 1.42-1.42L7.83 13H19v-2z"></path></svg></span><span class="handik-btn__label">' + backText + '</span>';
			const continueClass = 'handik-btn ' + ( settings.continueMuted ? 'is-pending' : 'is-primary' ) + ' is-continue';
			const backButton = settings.hideBack ? '' : '<button data-action="' + this.escape( backAction ) + '" class="' + backClass + '"' + ( settings.backMuted ? ' aria-disabled="true"' : '' ) + '>' + backInner + '</button>';
			const utilityButton = settings.utilityAction ? '<button data-action="' + this.escape( settings.utilityAction ) + '" class="handik-btn is-text">' + this.escape( settings.utilityLabel || '' ) + '</button>' : '';
			return '<div class="handik-footer-wrap"><div class="handik-footer-actions is-sticky-mobile' + ( settings.hideBack ? ' is-single' : '' ) + '">' + backButton + '<div class="handik-footer-actions__continue">' + utilityButton + '<button data-action="' + this.escape( continueAction ) + '" class="' + continueClass + '" aria-disabled="' + ( settings.continueMuted ? 'true' : 'false' ) + '">' + continueLabel + '</button></div></div></div>';
		}

		normalizePhone( value ) {
			const raw = String( value || '' ).trim();
			if ( ! raw ) {
				return '';
			}

			const digits = raw.replace( /\D/g, '' );
			if ( 10 === digits.length ) {
				return '+1' + digits;
			}
			if ( 11 === digits.length && '1' === digits.charAt( 0 ) ) {
				return '+' + digits;
			}
			if ( raw.charAt( 0 ) === '+' && digits.length >= 10 ) {
				return '+' + digits;
			}
			return digits ? '+' + digits : raw;
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
				zip_code: componentValue( 'postal_code', false )
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
					this.handleAction( button.getAttribute( 'data-action' ) );
				} );
			} );

			this.root.querySelectorAll( '[data-task-id]' ).forEach( ( button ) => {
				button.addEventListener( 'click', () => this.toggleTask( button.getAttribute( 'data-task-id' ) ) );
			} );

			this.root.querySelectorAll( '[data-model]' ).forEach( ( input ) => {
				const eventName = 'checkbox' === input.type || 'select-one' === input.type ? 'change' : 'input';
				input.addEventListener( eventName, () => {
					const value = 'checkbox' === input.type ? input.checked : input.value;
					this.setByPath( input.getAttribute( 'data-model' ), value );
				} );
				if ( 'contact.phone' === input.getAttribute( 'data-model' ) ) {
					input.addEventListener( 'blur', () => {
						const normalized = this.normalizePhone( input.value );
						this.state.contact.phone = normalized;
						input.value = normalized;
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
							zip_code: chosen.zip_code || ''
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
					this.state.photoUploading = true;
					this.clearFooterHint();
					this.render();
					try {
						await this.uploadFiles( photoInput.files );
						this.notify( 'success', 'Photos added', 'Your photos were uploaded and attached to this request.' );
					} catch ( error ) {
						this.setFooterHint( error.message, true );
					}
					this.state.photoUploading = false;
					this.render();
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
