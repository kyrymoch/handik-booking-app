( function( window, document ) {
	'use strict';

	const COMPLETE_EVENTS = [ 'handik.assistant_complete', 'handik.complete', 'assistant.complete' ];
	const DEEPLINK_EVENTS = [ 'handik-submit-result', 'handik-complete' ];
	const CHATKIT_TAG = 'openai-chatkit';
	const CHATKIT_TIMEOUT = 12000;

	function requestJson( url, data ) {
		return window.fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json'
			},
			body: JSON.stringify( data || {} )
		} ).then( function( response ) {
			return response.json().catch( function() {
				return {};
			} ).then( function( payload ) {
				if ( ! response.ok ) {
					throw new Error( payload.message || payload.error || 'Request failed' );
				}
				return payload;
			} );
		} );
	}

	function sanitizeKey( value ) {
		return String( value || '' ).trim().toLowerCase().replace( /[^a-z0-9_-]/g, '' );
	}

	function sanitizeText( value ) {
		return String( value || '' ).trim();
	}

	function normalizeResult( input ) {
		const value = input && typeof input === 'object' ? input : {};
		const allowed = [ 'standard_visit', 'extended_visit', 'large_visit', 'project_consultation' ];
		return {
			service_family: sanitizeKey( value.service_family ),
			rate_family: sanitizeKey( value.rate_family ),
			duration_bucket: sanitizeKey( value.duration_bucket ),
			booking_type: allowed.includes( value.booking_type ) ? value.booking_type : '',
			assistant_summary: sanitizeText( value.assistant_summary ),
			estimate_notes: sanitizeText( value.estimate_notes ),
			enough_information: Boolean( value.enough_information ),
			unsafe: Boolean( value.unsafe ),
			unsafe_reason: sanitizeText( value.unsafe_reason ),
			is_project: Boolean( value.is_project )
		};
	}

	function normalizeClientSecret( data ) {
		if ( ! data || typeof data !== 'object' ) {
			return '';
		}
		if ( typeof data.client_secret === 'string' ) {
			return data.client_secret;
		}
		if ( data.client_secret && typeof data.client_secret.value === 'string' ) {
			return data.client_secret.value;
		}
		if ( typeof data.clientSecret === 'string' ) {
			return data.clientSecret;
		}
		return '';
	}

	function summarizeError( error ) {
		if ( ! error ) {
			return 'Unknown error';
		}
		if ( typeof error === 'string' ) {
			return error;
		}
		return error.message || error.name || 'Unknown error';
	}

	function waitForChatKitElement() {
		if ( window.customElements && window.customElements.get( CHATKIT_TAG ) ) {
			return Promise.resolve();
		}
		if ( ! window.customElements || ! window.customElements.whenDefined ) {
			return Promise.reject( new Error( 'customElements not supported' ) );
		}
		return Promise.race( [
			window.customElements.whenDefined( CHATKIT_TAG ),
			new Promise( function( _, reject ) {
				window.setTimeout( function() {
					reject( new Error( 'ChatKit script did not load within ' + ( CHATKIT_TIMEOUT / 1000 ) + 's' ) );
				}, CHATKIT_TIMEOUT );
			} )
		] );
	}

	function mount( options ) {
		if ( ! options || ! options.container || ! options.requestId || ! options.draftToken ) {
			throw new Error( 'ChatKit bridge requires container, requestId, and draftToken.' );
		}

		const endpoints = options.endpoints || {};
		const diagnosticId = 'diag_' + Math.random().toString( 36 ).slice( 2 );
		let latestThreadId = '';
		let cachedSession = null;
		let handledSignature = '';
		let readyTimer = null;
		let chatkitReady = false;
		let chatkitInteractive = false;
		let element = null;

		const emitStatus = function( text, context ) {
			if ( typeof options.onStatus === 'function' ) {
				options.onStatus( text, context || {} );
			}
		};

		const log = function( level, message, context ) {
			const payload = {
				diagnostic_id: diagnosticId,
				request_id: options.requestId,
				level: level || 'info',
				message: message,
				context: context || {}
			};

			if ( 'error' === payload.level ) {
				console.error( '[HandikChatKitBridge]', payload.message, payload.context );
			} else {
				console.log( '[HandikChatKitBridge]', payload.message, payload.context );
			}

			emitStatus( payload.message, payload.context );

			if ( endpoints.clientLog ) {
				requestJson( endpoints.clientLog, payload ).catch( function() {} );
			}
		};

		const saveStructuredResult = function( result, source ) {
			const normalized = normalizeResult( result );
			const signature = JSON.stringify( normalized );
			if ( handledSignature === signature ) {
				log( 'debug', 'Structured result skipped as duplicate.', { source: source || 'unknown' } );
				return Promise.resolve();
			}
			handledSignature = signature;
			log( 'info', 'Structured result captured.', { source: source || 'unknown', booking_type: normalized.booking_type, unsafe: normalized.unsafe } );

			return requestJson( endpoints.saveAssistantResult, {
				request_id: options.requestId,
				draft_token: options.draftToken,
				assistant_result: normalized
			} ).then( function( payload ) {
				log( 'info', 'Structured result stored.', { source: source || 'unknown', unsafe_flag: payload.unsafe_flag || false } );
				if ( typeof options.onStructuredResult === 'function' ) {
					options.onStructuredResult( normalized, payload );
				}
				if ( typeof options.onComplete === 'function' ) {
					options.onComplete( normalized, payload );
				}
				return payload;
			} ).catch( function( error ) {
				log( 'error', 'Failed to store structured result.', { source: source || 'unknown', error: summarizeError( error ) } );
				throw error;
			} );
		};

		const associateThread = function( threadId ) {
			if ( ! threadId || ! endpoints.associateThread ) {
				return Promise.resolve();
			}
			latestThreadId = threadId;
			log( 'info', 'Thread change received.', { thread_id: threadId } );
			return requestJson( endpoints.associateThread, {
				request_id: options.requestId,
				draft_token: options.draftToken,
				thread_id: threadId
			} ).then( function() {
				log( 'info', 'Thread associated to draft request.', { thread_id: threadId } );
			} ).catch( function( error ) {
				log( 'error', 'Thread association failed.', { thread_id: threadId, error: summarizeError( error ) } );
				if ( typeof options.onError === 'function' ) {
					options.onError( error );
				}
			} );
		};

		const handleMountError = function( error ) {
			log( 'error', 'ChatKit mount failed.', { error: summarizeError( error ) } );
			if ( typeof options.onError === 'function' ) {
				options.onError( error );
			}
			throw error;
		};

		const markChatActive = function( source ) {
			if ( chatkitInteractive ) {
				return;
			}
			chatkitInteractive = true;
			if ( readyTimer ) {
				window.clearTimeout( readyTimer );
			}
			log( 'info', 'ChatKit became interactive.', { source: source } );
		};

		const buildOptions = function() {
			return {
				api: {
					getClientSecret: async function( currentSecret ) {
						log( 'debug', 'getClientSecret called.', { has_cached_session: !! cachedSession, has_current_secret: !! currentSecret } );
						if ( cachedSession ) {
							const cached = normalizeClientSecret( cachedSession );
							if ( cached ) {
								log( 'info', 'Using cached client secret.', { expires_after: cachedSession.expires_after || null } );
								cachedSession = null;
								return cached;
							}
						}
						const payload = await requestJson( endpoints.createSession, {
							request_id: options.requestId,
							draft_token: options.draftToken
						} );
						const normalized = normalizeClientSecret( payload );
						log( normalized ? 'info' : 'error', normalized ? 'Fetched fresh client secret.' : 'Session payload missing client secret.', {
							has_client_secret: !! normalized,
							has_file_upload: !! payload.file_upload
						} );
						return normalized || currentSecret || '';
					}
				},
				initialThread: latestThreadId || undefined,
				composer: {
					attachments: {
						enabled: true
					}
				}
			};
		};

		options.container.innerHTML = '<div class="handik-chatkit-bridge__loading">Loading assistant...</div>';
		log( 'info', 'Bridge mount started.', { request_id: options.requestId } );

		const ready = waitForChatKitElement().then( function() {
			log( 'info', 'ChatKit custom element is defined.' );
			return requestJson( endpoints.createSession, {
				request_id: options.requestId,
				draft_token: options.draftToken
			} );
		} ).then( function( session ) {
			const clientSecret = normalizeClientSecret( session );
			if ( ! clientSecret ) {
				throw new Error( 'No client secret in session response.' );
			}

			cachedSession = session;
			if ( session.draft_context && session.draft_context.chat_thread_id ) {
				latestThreadId = session.draft_context.chat_thread_id;
			}

			log( 'info', 'ChatKit session fetched.', {
				has_client_secret: true,
				has_thread: !! latestThreadId,
				has_file_upload: !! session.file_upload
			} );

			element = document.createElement( CHATKIT_TAG );
			element.style.display = 'block';
			element.style.width = '100%';
			element.style.minHeight = '520px';

			element.addEventListener( 'chatkit.ready', function() {
				chatkitReady = true;
				chatkitInteractive = true;
				if ( readyTimer ) {
					window.clearTimeout( readyTimer );
				}
				log( 'info', 'ChatKit ready event fired.' );
				if ( typeof options.onSessionReady === 'function' ) {
					options.onSessionReady();
				}
			} );

			element.addEventListener( 'chatkit.error', function( event ) {
				const detail = event && event.detail ? event.detail : {};
				const error = detail.error || detail;
				log( 'error', 'ChatKit runtime error.', { error: summarizeError( error ) } );
				if ( typeof options.onError === 'function' ) {
					options.onError( error && error.message ? error : new Error( summarizeError( error ) ) );
				}
			} );

			element.addEventListener( 'chatkit.log', function( event ) {
				const detail = event && event.detail ? event.detail : {};
				markChatActive( 'chatkit.log' );
				log( 'debug', 'ChatKit log event.', {
					name: detail.name || '',
					data: detail.data || {}
				} );
			} );

			element.addEventListener( 'chatkit.thread.change', function( event ) {
				const detail = event && event.detail ? event.detail : {};
				markChatActive( 'chatkit.thread.change' );
				if ( detail.threadId ) {
					associateThread( detail.threadId );
				}
			} );

			element.addEventListener( 'chatkit.effect', function( event ) {
				const detail = event && event.detail ? event.detail : {};
				markChatActive( 'chatkit.effect' );
				log( 'debug', 'ChatKit effect event.', { name: detail.name || '' } );
				if ( COMPLETE_EVENTS.includes( detail.name ) ) {
					saveStructuredResult( detail.data || {}, 'effect:' + detail.name );
				}
			} );

			element.addEventListener( 'chatkit.deeplink', function( event ) {
				const detail = event && event.detail ? event.detail : {};
				markChatActive( 'chatkit.deeplink' );
				log( 'debug', 'ChatKit deeplink event.', { name: detail.name || '' } );
				if ( DEEPLINK_EVENTS.includes( detail.name ) ) {
					saveStructuredResult( detail.data || {}, 'deeplink:' + detail.name );
				}
			} );

			element.addEventListener( 'message', function( event ) {
				const detail = event && event.detail ? event.detail : {};
				markChatActive( 'message' );
				log( 'debug', 'ChatKit message event.', {
					message_type: detail.type || '',
					role: detail.role || ''
				} );
			} );

			element.setOptions( buildOptions() );
			options.container.innerHTML = '';
			options.container.appendChild( element );
			log( 'info', 'ChatKit mounted into container.' );

			readyTimer = window.setTimeout( function() {
				if ( ! chatkitReady && ! chatkitInteractive ) {
					log( 'error', 'ChatKit ready timeout reached.', { timeout_ms: CHATKIT_TIMEOUT } );
					if ( typeof options.onError === 'function' ) {
						options.onError( new Error( 'Assistant UI did not finish loading.' ) );
					}
				}
			}, CHATKIT_TIMEOUT );

			return session;
		} ).catch( handleMountError );

		return {
			element: function() {
				return element;
			},
			ready: ready,
			unmount: function() {
				if ( readyTimer ) {
					window.clearTimeout( readyTimer );
				}
				if ( element && options.container.contains( element ) ) {
					options.container.removeChild( element );
				}
				log( 'info', 'ChatKit unmounted.' );
			}
		};
	}

	window.HandikChatKitBridge = {
		mount: mount
	};
}( window, document ) );
