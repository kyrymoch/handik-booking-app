( function( window, document ) {
	'use strict';

	const config = window.HandikBookingAppConfig || {};

	class HandikBookingApp {
		constructor( root ) {
			this.root = root;
			this.bootstrap = null;
			this.state = {
				step: 'client_type',
				clientType: '',
				verifiedProfile: null,
				requestId: 0,
				draftToken: '',
				selectedTasks: [],
				isProject: false,
				jobShape: '',
				preferredTimeframe: '',
				address: { address_id: 0, address_full: '', address_line_1: '', city: '', state: '', zip_code: '' },
				photos: [],
				shortDescription: '',
				assistantResult: null,
				contact: { first_name: '', last_name: '', full_name: '', email: '', phone: '' },
				bookingUrl: '',
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
			this.root.innerHTML = '<div class="handik-booking-app__shell"><div class="handik-booking-app__loading">' + this.escape( config.strings.loading || 'Loading...' ) + '</div></div>';
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
			this.state.step = step;
			this.render();
			if ( 'assistant' === step ) {
				window.setTimeout( () => this.mountAssistant(), 0 );
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
						this.render();
					}
					break;
				case 'booking-complete':
					await this.completeBookingStep();
					break;
				case 'assistant-next':
					await this.completeAssistantStep();
					break;
				case 'restart':
					this.state = Object.assign( this.state, {
						step: 'client_type',
						clientType: '',
						requestId: 0,
						draftToken: '',
						selectedTasks: [],
						isProject: false,
						jobShape: '',
						preferredTimeframe: '',
						address: { address_id: 0, address_full: '', address_line_1: '', city: '', state: '', zip_code: '' },
						photos: [],
						shortDescription: '',
						assistantResult: null,
						contact: { first_name: '', last_name: '', full_name: '', email: '', phone: '' },
						bookingUrl: '',
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
					this.goTo( this.state.clientType === 'returning_client' ? 'returning_verify' : 'client_type' );
					break;
				case 'back-contact':
					this.goTo( 'assistant' );
					break;
				case 'retry-assistant':
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
				this.state.loading = false;
				this.goTo( 'booking' );
			} catch ( error ) {
				this.state.loading = false;
				this.state.message = error.message;
				this.render();
			}
		}

		async completeBookingStep() {
			try {
				this.state.loading = true;
				this.render();
				await this.api( 'booking-complete', {
					request_id: this.state.requestId,
					draft_token: this.state.draftToken
				} );
				this.state.loading = false;
				this.goTo( 'success' );
			} catch ( error ) {
				this.state.loading = false;
				this.state.message = error.message;
				this.render();
			}
		}

		async completeAssistantStep() {
			if ( ! this.state.requestId || ! this.state.draftToken ) {
				this.setMessage( 'Assistant draft is not ready yet.' );
				return;
			}

			try {
				this.state.loading = true;
				this.render();
				const assistantPayload = this.state.assistantResult ? this.state.assistantResult : { enough_information: true };
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
				preferred_timeframe: this.state.preferredTimeframe,
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

			container.innerHTML = '';
			window.HandikChatKitBridge.mount( {
				container: container,
				requestId: this.state.requestId,
				draftToken: this.state.draftToken,
				endpoints: {
					createSession: config.restBase + 'chatkit-session',
					saveAssistantResult: config.restBase + 'assistant-result',
					associateThread: config.restBase + 'chatkit-thread',
					clientLog: config.restBase + 'client-log'
				},
				onStatus: ( message ) => {
					const note = this.root.querySelector( '.handik-booking-app__assistant-note' );
					if ( note ) {
						note.textContent = message;
						note.classList.remove( 'is-error' );
					}
				},
				onSessionReady: () => {
					const note = this.root.querySelector( '.handik-booking-app__assistant-note' );
					if ( note ) {
						note.textContent = 'Assistant UI loaded.';
					}
				},
				onComplete: ( normalized, payload ) => {
					this.state.assistantResult = Object.assign( {}, payload && payload.routing ? payload.routing : {}, normalized );
					if ( payload && payload.unsafe_flag ) {
						this.state.unsafeReason = payload.unsafe_reason || 'Unsafe request detected.';
						this.goTo( 'unsafe' );
						return;
					}
					this.state.message = '';
					this.prefillFromProfile();
					this.goTo( 'contact_details' );
				},
				onError: ( error ) => {
					const note = this.root.querySelector( '.handik-booking-app__assistant-note' );
					if ( note ) {
						note.textContent = error.message;
						note.classList.add( 'is-error' );
					}
				}
			} );
		}

		progressMarkup() {
			if ( ! this.bootstrap || ! Array.isArray( this.bootstrap.steps ) ) {
				return '';
			}
			const visible = this.bootstrap.steps.filter( ( step ) => ! [ 'welcome', 'returning_verify', 'unsafe' ].includes( step ) );
			const activeIndex = Math.max( 0, visible.indexOf( this.state.step ) );
			return '<div class="handik-booking-app__progress">' + visible.map( ( step, index ) => '<span class="' + ( index <= activeIndex ? 'is-active' : '' ) + '"></span>' ).join( '' ) + '</div>';
		}

		render() {
			const message = this.state.message ? '<div class="handik-booking-app__alert">' + this.escape( this.state.message ) + '</div>' : '';
			this.root.innerHTML = '<div class="handik-booking-app__shell">' + this.progressMarkup() + message + this.stepMarkup() + '</div>';
			this.bind();
			if ( 'assistant' === this.state.step ) {
				window.setTimeout( () => this.mountAssistant(), 0 );
			}
		}

		stepMarkup() {
			switch ( this.state.step ) {
				case 'client_type':
					return this.screen( 'Who is booking today?', '<div class="handik-choice-grid"><button data-action="choose-new" class="handik-choice">New client</button><button data-action="choose-returning" class="handik-choice">Returning client</button></div>' );
				case 'returning_verify':
					return this.screen(
						'Returning client verification',
						'<p>Enter your email or phone to receive a one-time code.</p>' +
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
					return this.screen( 'Assistant step', this.assistantMarkup(), 'is-wide' );
				case 'contact_details':
					return this.screen( 'Contact details', this.contactMarkup() );
				case 'booking':
					return this.screen( 'Book your time slot', this.bookingMarkup() );
				case 'success':
					return this.screen( 'Success', '<p>Your booking flow has been saved. If you opened the Cal.com calendar, your booking status will sync back automatically after confirmation.</p><button data-action="restart" class="handik-btn is-secondary">Start another booking</button>' );
				case 'unsafe':
					return this.screen( config.strings.unsafeTitle || 'Unsafe request', '<p>' + this.escape( this.state.unsafeReason || 'This request needs manual review before booking.' ) + '</p><button data-action="restart" class="handik-btn is-secondary">Back to start</button>' );
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
			const timeframe = this.state.preferredTimeframe || '';
			return '<div class="handik-task-groups">' +
				groups.map( ( group ) => '<div class="handik-task-group"><h3>' + this.escape( group.group ) + '</h3><div class="handik-task-grid">' +
					group.tasks.map( ( task ) => '<button type="button" class="handik-task ' + ( this.taskSelected( task.id ) ? 'is-selected' : '' ) + '" data-task-id="' + this.escape( task.id ) + '">' + this.escape( task.label ) + '</button>' ).join( '' ) +
				'</div></div>' ).join( '' ) +
				'<label class="handik-check"><input type="checkbox" data-model="isProject" ' + ( this.state.isProject ? 'checked' : '' ) + ' /> Project / Large Job</label>' +
				'<label class="handik-field"><span>Preferred timeframe</span><select data-model="preferredTimeframe"><option value="">Choose timeframe</option><option value="asap" ' + ( 'asap' === timeframe ? 'selected' : '' ) + '>ASAP</option><option value="this_week" ' + ( 'this_week' === timeframe ? 'selected' : '' ) + '>This week</option><option value="next_1_2_weeks" ' + ( 'next_1_2_weeks' === timeframe ? 'selected' : '' ) + '>Next 1-2 weeks</option><option value="flexible" ' + ( 'flexible' === timeframe ? 'selected' : '' ) + '>Flexible</option></select></label>' +
				'<div class="handik-footer-actions"><button data-action="' + ( this.state.clientType === 'returning_client' ? 'back-returning' : 'back-client' ) + '" class="handik-btn is-secondary">' + this.escape( config.strings.back ) + '</button><button data-action="tasks-next" class="handik-btn is-primary">' + this.escape( config.strings.continue ) + '</button></div>' +
			'</div>';
		}

		addressMarkup() {
			const addressOptions = this.state.verifiedProfile && Array.isArray( this.state.verifiedProfile.addresses ) ? this.state.verifiedProfile.addresses : [];
			return (
				( addressOptions.length ? '<label class="handik-field"><span>Saved address</span><select id="handik-saved-address"><option value="">Choose saved address</option>' + addressOptions.map( ( item ) => '<option value="' + item.id + '">' + this.escape( item.address_full ) + '</option>' ).join( '' ) + '</select></label>' : '' ) +
				this.input( 'Full address', 'address.address_full', 'text' ) +
				this.input( 'Address line 1', 'address.address_line_1', 'text' ) +
				'<div class="handik-grid-3">' + this.input( 'City', 'address.city', 'text' ) + this.input( 'State', 'address.state', 'text' ) + this.input( 'ZIP', 'address.zip_code', 'text' ) + '</div>' +
				'<label class="handik-field"><span>Photos</span><input type="file" id="handik-photo-input" multiple accept="image/*" />' +
				( this.state.photos.length ? '<div class="handik-photo-list">' + this.state.photos.map( ( photo ) => '<span>' + this.escape( photo.name || photo.url || 'photo' ) + '</span>' ).join( '' ) + '</div>' : '' ) +
				'</label>' +
				'<div class="handik-footer-actions"><button data-action="back-tasks" class="handik-btn is-secondary">' + this.escape( config.strings.back ) + '</button><button data-action="address-next" class="handik-btn is-primary">' + ( this.state.loading ? 'Saving...' : this.escape( config.strings.continue ) ) + '</button></div>'
			);
		}

		assistantMarkup() {
			const continueLabel = this.state.assistantResult ? 'Continue to contact details' : 'Continue when ready';
			return '<div class="handik-assistant-layout"><div><p class="handik-booking-app__assistant-note">OpenAI assistant opens here with your draft request context already attached.</p><div class="handik-booking-app__assistant-host"></div><div class="handik-footer-actions"><button data-action="back-tasks" class="handik-btn is-secondary">' + this.escape( config.strings.back ) + '</button><button data-action="assistant-next" class="handik-btn is-primary">' + this.escape( this.state.loading ? 'Saving...' : continueLabel ) + '</button></div></div><aside class="handik-sidebar"><h3>Draft summary</h3><ul><li><strong>Client type:</strong> ' + this.escape( this.state.clientType || 'n/a' ) + '</li><li><strong>Job shape:</strong> ' + this.escape( this.state.jobShape || 'n/a' ) + '</li><li><strong>Tasks:</strong> ' + this.escape( this.state.selectedTasks.join( ', ' ) || 'n/a' ) + '</li><li><strong>Address:</strong> ' + this.escape( this.state.address.address_full || 'n/a' ) + '</li></ul><button data-action="retry-assistant" class="handik-btn is-secondary">Retry assistant</button></aside></div>';
		}

		contactMarkup() {
			if ( this.state.verifiedProfile && this.state.verifiedProfile.contact && ! this.state.contact.full_name ) {
				this.prefillFromProfile();
			}
			return this.input( 'Full name', 'contact.full_name', 'text' ) +
				this.input( 'Email', 'contact.email', 'email' ) +
				this.input( 'Phone', 'contact.phone', 'tel' ) +
				'<div class="handik-footer-actions"><button data-action="back-contact" class="handik-btn is-secondary">' + this.escape( config.strings.back ) + '</button><button data-action="contact-next" class="handik-btn is-primary">' + ( this.state.loading ? 'Preparing...' : this.escape( config.strings.continue ) ) + '</button></div>';
		}

		bookingMarkup() {
			const summary = this.state.assistantResult ? ( this.state.assistantResult.assistant_summary || '' ) : '';
			return '<div class="handik-admin-card-like"><p><strong>Booking type:</strong> ' + this.escape( this.state.assistantResult && this.state.assistantResult.booking_type ? this.state.assistantResult.booking_type : 'pending' ) + '</p><p><strong>Assistant summary:</strong> ' + this.escape( summary || 'Summary will be sent to booking notes.' ) + '</p></div>' +
				'<div class="handik-footer-actions"><button data-action="open-booking" class="handik-btn is-primary">' + this.escape( config.strings.openBooking ) + '</button><button data-action="booking-complete" class="handik-btn is-secondary">' + this.escape( config.strings.completeBooking ) + '</button></div>';
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
