( function( window, document ) {
	'use strict';

	const config = window.HandikBookingAppConfig || {};
	const GOOGLE_SCRIPT_ID = 'handik-google-maps-places';

	class HandikBookingApp {
		constructor( root ) {
			this.root = root;
			this.bootstrap = null;
			this.addressAutocomplete = null;
			this.bookingStatusTimer = null;
			this.googleMapsPromise = null;
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
				address: { address_id: 0, address_full: '', address_line_1: '', city: '', state: '', zip_code: '' },
				photos: [],
				shortDescription: '',
				assistantResult: null,
				assistantUserMessageSent: false,
				assistantThreadId: '',
				contact: { first_name: '', last_name: '', full_name: '', email: '', phone: '' },
				bookingUrl: '',
				bookingStatus: '',
				bookingStatusMessage: '',
				unsafeReason: '',
				appSessionKey: 'app_' + Math.random().toString( 36 ).slice( 2 ),
				message: '',
				loading: false,
				bookingOpened: false
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

		setMessage( message ) {
			this.state.message = message || '';
			this.render();
		}

		goTo( step ) {
			if ( 'booking' === this.state.step && 'booking' !== step ) {
				this.stopBookingStatusPolling();
			}

			this.state.step = step;
			this.render();
			if ( 'assistant' === step ) {
				window.setTimeout( () => this.mountAssistant(), 0 );
			}
			if ( 'address_photos' === step ) {
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
			}
			this.state.jobShape = this.state.isProject ? 'project' : ( this.state.selectedTasks.length > 1 ? 'multiple_tasks' : 'single_task' );
			this.render();
		}

		async handleAction( action ) {
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
				case 'send-code':
					await this.sendCode();
					break;
				case 'verify-code':
					await this.verifyCode();
					break;
				case 'tasks-next':
					if ( ! this.state.selectedTasks.length && ! this.state.isProject ) {
						this.setMessage( 'Select at least one task or mark this as a project.' );
						return;
					}
					this.state.jobShape = this.state.isProject ? 'project' : ( this.state.selectedTasks.length > 1 ? 'multiple_tasks' : 'single_task' );
					this.goTo( 'address_photos' );
					break;
				case 'address-next':
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
						address: { address_id: 0, address_full: '', address_line_1: '', city: '', state: '', zip_code: '' },
						photos: [],
						shortDescription: '',
						assistantResult: null,
						assistantUserMessageSent: false,
						assistantThreadId: '',
						contact: { first_name: '', last_name: '', full_name: '', email: '', phone: '' },
						bookingUrl: '',
						bookingStatus: '',
						bookingStatusMessage: '',
						unsafeReason: '',
						message: '',
						bookingOpened: false
					} );
					this.goTo( 'client_type' );
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
					this.goTo( 'address_photos' );
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
			try {
				this.state.loading = true;
				this.render();
				const response = await this.api( 'auth/request-code', {
					email: this.state.contact.email,
					phone: this.state.contact.phone,
					redirect: window.location.href
				} );
				this.state.message = response.message;
			} catch ( error ) {
				this.state.message = error.message;
			}
			this.state.loading = false;
			this.render();
		}

		async verifyCode() {
			const codeInput = this.root.querySelector( '[name="verification_code"]' );
			try {
				this.state.loading = true;
				this.render();
				const response = await this.api( 'auth/verify', {
					email: this.state.contact.email,
					phone: this.state.contact.phone,
					code: codeInput ? codeInput.value.trim() : ''
				} );
				this.state.verifiedProfile = response.profile;
				this.prefillFromProfile();
				this.state.message = 'Verification successful.';
				this.state.loading = false;
				this.goTo( 'task_selection' );
			} catch ( error ) {
				this.state.loading = false;
				this.state.message = error.message;
				this.render();
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
			for ( const file of files ) {
				const formData = new window.FormData();
				formData.append( 'file', file );
				const uploaded = await this.api( 'app/upload', {}, 'POST', formData );
				this.state.photos.push( uploaded );
			}
		}

		async saveAddressAndDraft() {
			if ( ! this.state.address.address_full ) {
				this.setMessage( 'Address is required.' );
				return;
			}
			try {
				this.state.loading = true;
				this.render();
				const response = await this.api( 'app/draft', this.buildDraftPayload( 'assistant' ) );
				this.state.requestId = response.request_id;
				this.state.draftToken = response.draft_token;
				this.state.message = '';
				this.state.loading = false;
				this.goTo( 'assistant' );
			} catch ( error ) {
				this.state.loading = false;
				this.state.message = error.message;
				this.render();
			}
		}

		async saveContactAndPrepareBooking() {
			if ( ! this.state.contact.full_name || ! this.state.contact.email ) {
				this.setMessage( 'Name and email are required.' );
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
				this.state.message = error.message;
				this.render();
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
					this.goTo( 'success' );
					return true;
				}

				if ( 'cancelled' === this.state.bookingStatus ) {
					this.setBookingStatusMessage( config.strings.bookingCancelled || 'This booking was cancelled. You can book another slot below.', true );
				} else if ( payload.cal_booking_id ) {
					this.setBookingStatusMessage( config.strings.bookingWaiting || 'Stay on this screen while we wait for Cal.com to confirm the booking.', false );
				} else if ( showMessage ) {
					this.setBookingStatusMessage( 'We have not received a Cal.com booking confirmation yet.', false );
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
			const note = this.root.querySelector( '.handik-booking-app__booking-note' );
			if ( note ) {
				note.textContent = this.state.bookingStatusMessage;
				note.classList.toggle( 'is-error', !! isError );
			}
		}

		async completeAssistantStep() {
			if ( ! this.state.requestId || ! this.state.draftToken ) {
				this.setMessage( 'Assistant draft is not ready yet.' );
				return;
			}

			if ( ! this.state.assistantResult && ! this.state.assistantUserMessageSent ) {
				this.setMessage( 'Send the virtual assistant a short message about the job before continuing.' );
				return;
			}

			try {
				this.state.loading = true;
				this.render();
				const assistantPayload = this.state.assistantResult ? Object.assign( {}, this.state.assistantResult ) : { enough_information: true };
				const payload = await this.api( 'assistant-result', {
					request_id: this.state.requestId,
					draft_token: this.state.draftToken,
					assistant_result: assistantPayload
				} );

				this.state.assistantResult = Object.assign( {}, payload.routing || {}, this.state.assistantResult || {} );
				this.state.loading = false;

				if ( payload && payload.unsafe_flag ) {
					this.state.unsafeReason = payload.unsafe_reason || 'Unsafe request detected.';
					this.goTo( 'unsafe' );
					return;
				}

				this.goTo( 'contact_details' );
			} catch ( error ) {
				this.state.loading = false;
				this.state.message = error.message;
				this.render();
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
				endpoints: {
					createSession: config.restBase + 'chatkit-session',
					saveAssistantResult: config.restBase + 'assistant-result',
					associateThread: config.restBase + 'chatkit-thread',
					clientLog: config.restBase + 'client-log'
				},
				onSessionReady: () => {
					const note = this.root.querySelector( '.handik-booking-app__assistant-note' );
					if ( note ) {
						note.textContent = config.strings.assistantHelper || 'Describe the job in chat, ask questions if needed, then tap Continue when you are ready.';
					}
				},
				onThreadChange: ( threadId ) => {
					this.state.assistantThreadId = threadId || this.state.assistantThreadId;
				},
				onMessageActivity: ( detail ) => {
					const role = detail && ( detail.role || detail.author_role || ( detail.message && detail.message.role ) ) ? String( detail.role || detail.author_role || detail.message.role ).toLowerCase() : '';
					if ( 'user' === role ) {
						this.state.assistantUserMessageSent = true;
					}
				},
				onComplete: ( normalized, payload ) => {
					this.state.assistantResult = Object.assign( {}, payload && payload.routing ? payload.routing : {}, normalized );
					this.state.assistantUserMessageSent = true;
					if ( payload && payload.unsafe_flag ) {
						this.state.unsafeReason = payload.unsafe_reason || 'Unsafe request detected.';
						this.goTo( 'unsafe' );
						return;
					}
					this.state.message = 'The virtual assistant is ready. Continue when you are ready.';
					this.render();
				},
				onError: ( error ) => {
					this.setMessage( error.message || 'The virtual assistant had trouble loading. Give it another moment, then send a short message about the job.' );
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
			const message = this.state.message ? '<div class="handik-booking-app__alert">' + this.escape( this.state.message ) + '</div>' : '';
			this.root.innerHTML = '<div class="handik-booking-app__shell">' + this.progressMarkup() + message + this.stepMarkup() + '</div>';
			this.bind();
			if ( 'assistant' === this.state.step ) {
				window.setTimeout( () => this.mountAssistant(), 0 );
			}
			if ( 'address_photos' === this.state.step ) {
				window.setTimeout( () => this.mountAddressAutocomplete(), 0 );
			}
			if ( 'booking' === this.state.step ) {
				window.setTimeout( () => this.startBookingStatusPolling(), 0 );
			}
		}

		stepMarkup() {
			switch ( this.state.step ) {
				case 'client_type':
					return this.screen( 'Who is booking today?', '<div class="handik-choice-grid"><button data-action="choose-new" class="handik-choice">New client</button><button data-action="choose-returning" class="handik-choice">Returning client</button></div>' );
				case 'returning_verify':
					return this.screen(
						'Returning client verification',
						'<p class="handik-booking-app__intro">Enter your email or phone to receive a one-time code.</p>' +
						this.input( 'Email', 'contact.email', 'email' ) +
						this.input( 'Phone', 'contact.phone', 'tel' ) +
						'<div class="handik-inline-actions"><button data-action="send-code" class="handik-btn is-secondary">' + this.escape( config.strings.sendCode ) + '</button></div>' +
						'<label class="handik-field"><span>Verification code</span><input name="verification_code" type="text" placeholder="6-digit code" /></label>' +
						'<div class="handik-footer-actions"><button data-action="back-client" class="handik-btn is-secondary">' + this.escape( config.strings.back ) + '</button><button data-action="verify-code" class="handik-btn is-primary">' + this.escape( config.strings.verify ) + '</button></div>'
					);
				case 'task_selection':
					return this.screen( 'What do you need help with?', this.tasksMarkup() );
				case 'address_photos':
					return this.screen( 'Address and photos', this.addressMarkup() );
				case 'assistant':
					return this.screen( 'Virtual assistant', this.assistantMarkup(), 'is-wide' );
				case 'contact_details':
					return this.screen( 'Contact details', this.contactMarkup() );
				case 'booking':
					return this.screen( 'Book your time slot', this.bookingMarkup() );
				case 'success':
					return this.screen( 'Success', '<p class="handik-booking-app__intro">Your booking has been confirmed and saved.</p><button data-action="restart" class="handik-btn is-secondary">Start another booking</button>' );
				case 'unsafe':
					return this.screen( config.strings.unsafeTitle || 'Unsafe request', '<p class="handik-booking-app__intro">' + this.escape( this.state.unsafeReason || 'This request needs manual review before booking.' ) + '</p><button data-action="restart" class="handik-btn is-secondary">Back to start</button>' );
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
			return '<div class="handik-task-groups">' +
				groups.map( ( group ) => '<div class="handik-task-group"><h3>' + this.escape( group.group ) + '</h3><div class="handik-task-grid">' +
					group.tasks.map( ( task ) => '<button type="button" class="handik-task ' + ( this.taskSelected( task.id ) ? 'is-selected' : '' ) + '" data-task-id="' + this.escape( task.id ) + '">' + this.escape( task.label ) + '</button>' ).join( '' ) +
				'</div></div>' ).join( '' ) +
				'<label class="handik-check"><input type="checkbox" data-model="isProject" ' + ( this.state.isProject ? 'checked' : '' ) + ' /> Project / Large Job</label>' +
				this.footerActions( this.state.clientType === 'returning_client' ? 'back-returning' : 'back-client', 'tasks-next', this.escape( config.strings.continue ) ) +
			'</div>';
		}

		addressMarkup() {
			const addressOptions = this.state.verifiedProfile && Array.isArray( this.state.verifiedProfile.addresses ) ? this.state.verifiedProfile.addresses : [];
			return (
				( addressOptions.length ? '<label class="handik-field"><span>Saved address</span><select id="handik-saved-address"><option value="">Choose saved address</option>' + addressOptions.map( ( item ) => '<option value="' + item.id + '">' + this.escape( item.address_full ) + '</option>' ).join( '' ) + '</select></label>' : '' ) +
				'<label class="handik-field handik-field--address"><span>Address of the job</span><input id="handik-job-address" type="text" data-model="address.address_full" placeholder="' + this.escape( config.strings.addressPlaceholder || 'Start typing the address of the job' ) + '" value="' + this.escape( this.state.address.address_full || '' ) + '" /></label>' +
				'<label class="handik-field"><span>Photos</span><input type="file" id="handik-photo-input" multiple accept="image/*" />' +
				( this.state.photos.length ? '<div class="handik-photo-list">' + this.state.photos.map( ( photo ) => '<span>' + this.escape( photo.name || photo.url || 'photo' ) + '</span>' ).join( '' ) + '</div>' : '' ) +
				'</label>' +
				this.footerActions( 'back-tasks', 'address-next', this.state.loading ? 'Saving...' : this.escape( config.strings.continue ) )
			);
		}

		assistantMarkup() {
			const continueLabel = this.state.assistantResult ? 'Continue to contact details' : 'Continue';
			return '<div class="handik-assistant-layout"><div class="handik-assistant-panel"><p class="handik-booking-app__assistant-note">' + this.escape( config.strings.assistantHelper || 'Describe the job in chat, ask questions if needed, then tap Continue when you are ready.' ) + '</p><div class="handik-booking-app__assistant-host"></div>' + this.footerActions( 'back-address', 'assistant-next', this.state.loading ? 'Saving...' : continueLabel ) + '</div></div>';
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
				this.footerActions( 'back-contact', 'contact-next', this.state.loading ? 'Preparing...' : this.escape( config.strings.continue ) );
		}

		bookingMarkup() {
			const summary = this.state.assistantResult ? ( this.state.assistantResult.assistant_summary || '' ) : '';
			const note = this.state.bookingStatusMessage || config.strings.bookingWaiting || 'Stay on this screen while we wait for Cal.com to confirm the booking.';
			const frame = this.state.bookingUrl
				? '<div class="handik-booking-app__booking-frame-wrap"><iframe class="handik-booking-app__booking-frame" src="' + this.escape( this.state.bookingUrl ) + '" title="Cal.com booking calendar" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="fullscreen"></iframe></div>'
				: '<div class="handik-admin-card-like"><p>Booking calendar is not ready yet.</p></div>';

			return '<div class="handik-admin-card-like"><p><strong>Booking type:</strong> ' + this.escape( this.state.assistantResult && this.state.assistantResult.booking_type ? this.state.assistantResult.booking_type : 'pending' ) + '</p><p><strong>Assistant summary:</strong> ' + this.escape( summary || 'Summary will be sent to booking notes.' ) + '</p></div>' +
				'<p class="handik-booking-app__booking-note">' + this.escape( note ) + '</p>' +
				frame +
				this.footerActions( 'open-booking', 'refresh-booking-status', this.escape( config.strings.completeBooking ), this.escape( config.strings.openBooking ), { backIsUtility: true } );
		}

		footerActions( backAction, continueAction, continueLabel, backLabel, options ) {
			const settings = options || {};
			const backText = backLabel || this.escape( config.strings.back );
			const backClass = settings.backIsUtility ? 'handik-btn is-secondary' : 'handik-btn is-secondary is-back';
			const backInner = settings.backIsUtility ? '<span class="handik-btn__label">' + backText + '</span>' : '<span class="handik-btn__icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M19 11H7.83l4.88-4.88L11.29 4.7 4 12l7.29 7.3 1.42-1.42L7.83 13H19v-2z"></path></svg></span><span class="handik-btn__label">' + backText + '</span>';
			return '<div class="handik-footer-actions is-sticky-mobile"><button data-action="' + this.escape( backAction ) + '" class="' + backClass + '">' + backInner + '</button><button data-action="' + this.escape( continueAction ) + '" class="handik-btn is-primary is-continue">' + continueLabel + '</button></div>';
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
			const lineOne = [ streetNumber, route, subpremise ].filter( Boolean ).join( ' ' ).trim();

			return {
				address_full: place.formatted_address || this.state.address.address_full || '',
				address_line_1: lineOne || place.formatted_address || '',
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
					this.state.message = config.strings.uploading || 'Uploading...';
					this.render();
					try {
						await this.uploadFiles( photoInput.files );
						this.state.message = '';
					} catch ( error ) {
						this.state.message = error.message;
					}
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
