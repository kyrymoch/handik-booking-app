( function( window, document ) {
	'use strict';

	const COMPLETE_EVENTS = [ 'handik.assistant_complete', 'handik.complete', 'assistant.complete' ];
	const DEEPLINK_EVENTS = [ 'handik-submit-result', 'handik-complete' ];
	const CHATKIT_TAG = 'openai-chatkit';
	const CHATKIT_TIMEOUT = 12000;
	const BRIDGE_CACHE = new Map();

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
			is_project: Boolean( value.is_project ),
			next_message: sanitizeText( value.next_message )
		};
	}

	function tryParseJson( value ) {
		if ( ! value || 'string' !== typeof value ) {
			return null;
		}
		try {
			const parsed = JSON.parse( value );
			return parsed && 'object' === typeof parsed ? parsed : null;
		} catch ( error ) {
			return null;
		}
	}

	function looksLikeStructuredResult( value ) {
		if ( ! value || 'object' !== typeof value ) {
			return false;
		}

		const required = [ 'service_family', 'rate_family', 'duration_bucket', 'booking_type', 'assistant_summary', 'estimate_notes', 'enough_information', 'unsafe', 'unsafe_reason', 'is_project', 'next_message' ];
		const hasRequired = required.every( function( key ) {
			return Object.prototype.hasOwnProperty.call( value, key );
		} );

		if ( hasRequired ) {
			return true;
		}

		return Object.prototype.hasOwnProperty.call( value, 'enough_information' ) &&
			(
				Object.prototype.hasOwnProperty.call( value, 'booking_type' ) ||
				Object.prototype.hasOwnProperty.call( value, 'assistant_summary' ) ||
				Object.prototype.hasOwnProperty.call( value, 'next_message' )
			);
	}

	function extractStructuredResult( detail ) {
		const candidates = [];
		const queueCandidate = function( candidate ) {
			if ( candidate && 'object' === typeof candidate ) {
				candidates.push( candidate );
			}
			const parsed = tryParseJson( candidate );
			if ( parsed ) {
				candidates.push( parsed );
			}
		};

		if ( detail && 'object' === typeof detail ) {
			queueCandidate( detail );
			queueCandidate( detail.data );
			queueCandidate( detail.result );
			queueCandidate( detail.output );
			queueCandidate( detail.payload );
			queueCandidate( detail.arguments );
			queueCandidate( detail.message );
			if ( detail.message && 'object' === typeof detail.message ) {
				queueCandidate( detail.message.data );
				queueCandidate( detail.message.result );
				queueCandidate( detail.message.output );
				queueCandidate( detail.message.payload );
				queueCandidate( detail.message.arguments );
			}
		}

		return candidates.find( function( candidate ) {
			return looksLikeStructuredResult( candidate );
		} ) || null;
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
		const cacheKey = String( options.cacheKey || options.requestId );
		const cachedRecord = BRIDGE_CACHE.get( cacheKey );

		if ( cachedRecord && cachedRecord.element ) {
			cachedRecord.options = options;
			options.container.innerHTML = '';
			options.container.appendChild( cachedRecord.element );
			window.requestAnimationFrame( function() {
				if ( 'function' === typeof cachedRecord.prepareComposerFiles ) {
					cachedRecord.prepareComposerFiles();
				}
			} );
			if ( cachedRecord.ready && typeof options.onSessionReady === 'function' ) {
				options.onSessionReady();
			}
			if ( cachedRecord.latestThreadId && typeof options.onThreadChange === 'function' ) {
				options.onThreadChange( cachedRecord.latestThreadId );
			}
			return {
				element: function() {
					return cachedRecord.element;
				},
				ready: Promise.resolve( cachedRecord.session || null ),
				unmount: function() {}
			};
		}

		const record = {
			options: options,
			latestThreadId: options.initialThreadId || '',
			cachedSession: null,
			handledSignature: '',
			readyTimer: null,
			ready: false,
			interactive: false,
			element: null,
			session: null,
			preparedFilesSignature: '',
			preparedFilesCount: 0,
			prepareComposerFiles: null
		};

		BRIDGE_CACHE.set( cacheKey, record );

		const emitStatus = function( text, context ) {
			if ( typeof record.options.onStatus === 'function' ) {
				record.options.onStatus( text, context || {} );
			}
		};

		const getPendingFiles = function() {
			if ( 'function' === typeof record.options.pendingFiles ) {
				try {
					const files = record.options.pendingFiles();
					return Array.isArray( files ) ? files.filter( function( file ) {
						return file instanceof window.File;
					} ) : [];
				} catch ( error ) {
					return [];
				}
			}

			return Array.isArray( record.options.pendingFiles ) ? record.options.pendingFiles.filter( function( file ) {
				return file instanceof window.File;
			} ) : [];
		};

		const pendingFilesSignature = function( files ) {
			return ( files || [] ).map( function( file ) {
				return [ file.name || '', file.size || 0, file.lastModified || 0, file.type || '' ].join( ':' );
			} ).join( '|' );
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
			if ( record.handledSignature === signature ) {
				log( 'debug', 'Structured result skipped as duplicate.', { source: source || 'unknown' } );
				return Promise.resolve();
			}
			record.handledSignature = signature;
			log( 'info', 'Structured result captured.', { source: source || 'unknown', booking_type: normalized.booking_type, unsafe: normalized.unsafe } );

			return requestJson( endpoints.saveAssistantResult, {
				request_id: record.options.requestId,
				draft_token: record.options.draftToken,
				assistant_result: normalized
			} ).then( function( payload ) {
				log( 'info', 'Structured result stored.', { source: source || 'unknown', unsafe_flag: payload.unsafe_flag || false } );
				if ( typeof record.options.onStructuredResult === 'function' ) {
					record.options.onStructuredResult( normalized, payload );
				}
				if ( typeof record.options.onComplete === 'function' ) {
					record.options.onComplete( normalized, payload );
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
			record.latestThreadId = threadId;
			log( 'info', 'Thread change received.', { thread_id: threadId } );
			return requestJson( endpoints.associateThread, {
				request_id: record.options.requestId,
				draft_token: record.options.draftToken,
				thread_id: threadId
			} ).then( function() {
				log( 'info', 'Thread associated to draft request.', { thread_id: threadId } );
				if ( typeof record.options.onThreadChange === 'function' ) {
					record.options.onThreadChange( threadId );
				}
			} ).catch( function( error ) {
				log( 'error', 'Thread association failed.', { thread_id: threadId, error: summarizeError( error ) } );
				if ( typeof record.options.onError === 'function' ) {
					record.options.onError( error );
				}
			} );
		};

		const handleMountError = function( error ) {
			log( 'error', 'ChatKit mount failed.', { error: summarizeError( error ) } );
			if ( typeof record.options.onError === 'function' ) {
				record.options.onError( error );
			}
			throw error;
		};

		const markChatActive = function( source ) {
			if ( record.interactive ) {
				return;
			}
			record.interactive = true;
			if ( record.readyTimer ) {
				window.clearTimeout( record.readyTimer );
			}
			log( 'info', 'ChatKit became interactive.', { source: source } );
		};

		const prepareComposerFiles = function() {
			const files = getPendingFiles();
			const signature = pendingFilesSignature( files );

			if ( ! files.length ) {
				record.preparedFilesSignature = '';
				record.preparedFilesCount = 0;
				return Promise.resolve( false );
			}

			if ( signature && signature === record.preparedFilesSignature ) {
				return Promise.resolve( false );
			}

			if ( ! record.element || 'function' !== typeof record.element.setComposerValue ) {
				log( 'debug', 'ChatKit composer file prefill is not supported by the current element.' );
				return Promise.resolve( false );
			}

			return Promise.resolve( record.element.setComposerValue( { files: files } ) ).then( function() {
				record.preparedFilesSignature = signature;
				record.preparedFilesCount = files.length;
				log( 'info', 'Prepared pending photos in ChatKit composer.', { file_count: files.length } );
				return true;
			} ).catch( function( error ) {
				log( 'error', 'Assistant file prefill failed.', { error: summarizeError( error ) } );
				return false;
			} );
		};

		record.prepareComposerFiles = prepareComposerFiles;

		const buildOptions = function() {
			return {
				api: {
					getClientSecret: async function( currentSecret ) {
						log( 'debug', 'getClientSecret called.', { has_cached_session: !! record.cachedSession, has_current_secret: !! currentSecret } );
						if ( record.cachedSession ) {
							const cached = normalizeClientSecret( record.cachedSession );
							if ( cached ) {
								log( 'info', 'Using cached client secret.', { expires_after: record.cachedSession.expires_after || null } );
								record.cachedSession = null;
								return cached;
							}
						}
						const payload = await requestJson( endpoints.createSession, {
							request_id: record.options.requestId,
							draft_token: record.options.draftToken
						} );
						const normalized = normalizeClientSecret( payload );
						log( normalized ? 'info' : 'error', normalized ? 'Fetched fresh client secret.' : 'Session payload missing client secret.', {
							has_client_secret: !! normalized,
							has_file_upload: !! payload.file_upload
						} );
						return normalized || currentSecret || '';
					}
				},
				startScreen: options.startScreenGreeting ? {
					greeting: record.options.startScreenGreeting
				} : undefined,
				initialThread: record.latestThreadId || undefined,
				composer: {
					placeholder: record.options.composerPlaceholder || undefined
				}
			};
		};

		options.container.innerHTML = '<div class="handik-chatkit-bridge__loading"><div class="handik-loading-visual handik-loading-visual--square" aria-hidden="true"><span class="handik-loading-square"></span></div><strong>' + ( record.options.loadingTitle || 'Загрузка...' ) + '</strong></div>';
		log( 'info', 'Bridge mount started.', { request_id: record.options.requestId } );

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

			record.cachedSession = session;
			record.session = session;
			if ( session.draft_context && session.draft_context.chat_thread_id ) {
				record.latestThreadId = session.draft_context.chat_thread_id;
			}

			log( 'info', 'ChatKit session fetched.', {
				has_client_secret: true,
				has_thread: !! record.latestThreadId,
				has_file_upload: !! session.file_upload
			} );

			record.element = document.createElement( CHATKIT_TAG );
			record.element.style.display = 'block';
			record.element.style.width = '100%';
			record.element.style.minHeight = '520px';

			record.element.addEventListener( 'chatkit.ready', function() {
				record.ready = true;
				record.interactive = true;
				if ( record.readyTimer ) {
					window.clearTimeout( record.readyTimer );
				}
				log( 'info', 'ChatKit ready event fired.' );
				prepareComposerFiles();
				if ( typeof record.options.onSessionReady === 'function' ) {
					record.options.onSessionReady();
				}
			} );

			record.element.addEventListener( 'chatkit.error', function( event ) {
				const detail = event && event.detail ? event.detail : {};
				const error = detail.error || detail;
				log( 'error', 'ChatKit runtime error.', { error: summarizeError( error ) } );
				if ( typeof record.options.onError === 'function' ) {
					record.options.onError( error && error.message ? error : new Error( summarizeError( error ) ) );
				}
			} );

			record.element.addEventListener( 'chatkit.log', function( event ) {
				const detail = event && event.detail ? event.detail : {};
				markChatActive( 'chatkit.log' );
				log( 'debug', 'ChatKit log event.', {
					name: detail.name || '',
					data: detail.data || {}
				} );
				if ( 'composer.submit' === detail.name && typeof record.options.onComposerSubmit === 'function' ) {
					record.options.onComposerSubmit( {
						attachmentsCount: detail.data && detail.data.attachmentsCount ? detail.data.attachmentsCount : 0,
						preparedFilesCount: record.preparedFilesCount,
						preparedFilesSignature: record.preparedFilesSignature
					} );
					record.preparedFilesSignature = '';
					record.preparedFilesCount = 0;
				}
				const structured = extractStructuredResult( detail );
				if ( structured ) {
					saveStructuredResult( structured, 'log:' + ( detail.name || 'unknown' ) );
				}
			} );

			record.element.addEventListener( 'chatkit.thread.change', function( event ) {
				const detail = event && event.detail ? event.detail : {};
				markChatActive( 'chatkit.thread.change' );
				if ( detail.threadId ) {
					associateThread( detail.threadId );
				}
			} );

			record.element.addEventListener( 'chatkit.effect', function( event ) {
				const detail = event && event.detail ? event.detail : {};
				markChatActive( 'chatkit.effect' );
				log( 'debug', 'ChatKit effect event.', { name: detail.name || '' } );
				const structured = extractStructuredResult( detail );
				if ( COMPLETE_EVENTS.includes( detail.name ) || structured ) {
					saveStructuredResult( structured || detail.data || {}, 'effect:' + ( detail.name || 'unknown' ) );
				}
			} );

			record.element.addEventListener( 'chatkit.deeplink', function( event ) {
				const detail = event && event.detail ? event.detail : {};
				markChatActive( 'chatkit.deeplink' );
				log( 'debug', 'ChatKit deeplink event.', { name: detail.name || '' } );
				const structured = extractStructuredResult( detail );
				if ( DEEPLINK_EVENTS.includes( detail.name ) || structured ) {
					saveStructuredResult( structured || detail.data || {}, 'deeplink:' + ( detail.name || 'unknown' ) );
				}
			} );

			record.element.addEventListener( 'message', function( event ) {
				const detail = event && event.detail ? event.detail : {};
				markChatActive( 'message' );
				log( 'debug', 'ChatKit message event.', {
					message_type: detail.type || '',
					role: detail.role || ''
				} );
				if ( typeof record.options.onMessageActivity === 'function' ) {
					record.options.onMessageActivity( detail );
				}
				const structured = extractStructuredResult( detail );
				if ( structured ) {
					saveStructuredResult( structured, 'message' );
				}
			} );

			record.element.setOptions( buildOptions() );
			options.container.innerHTML = '';
			options.container.appendChild( record.element );
			log( 'info', 'ChatKit mounted into container.' );

			record.readyTimer = window.setTimeout( function() {
				if ( ! record.ready && ! record.interactive ) {
					log( 'debug', 'ChatKit ready timeout reached without a visible UI error.', { timeout_ms: CHATKIT_TIMEOUT } );
				}
			}, CHATKIT_TIMEOUT );

			return session;
		} ).catch( handleMountError );

		return {
			element: function() {
				return record.element;
			},
			ready: ready,
			unmount: function() {
				if ( record.readyTimer ) {
					window.clearTimeout( record.readyTimer );
				}
				if ( record.element && options.container.contains( record.element ) ) {
					options.container.removeChild( record.element );
				}
				log( 'info', 'ChatKit unmounted.' );
			}
		};
	}

	function reset( cacheKey ) {
		const key = String( cacheKey || '' );
		if ( ! key || ! BRIDGE_CACHE.has( key ) ) {
			return;
		}
		BRIDGE_CACHE.delete( key );
	}

	window.HandikChatKitBridge = {
		mount: mount,
		reset: reset
	};
}( window, document ) );
