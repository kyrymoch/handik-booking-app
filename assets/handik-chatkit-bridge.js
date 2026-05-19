( function( window, document ) {
	'use strict';

	const COMPLETE_EVENTS = [ 'handik.assistant_complete', 'handik.complete', 'assistant.complete' ];
	const DEEPLINK_EVENTS = [ 'handik-submit-result', 'handik-complete' ];
	const CHATKIT_TAG = 'openai-chatkit';
	const CHATKIT_TIMEOUT = 12000;
	const BRIDGE_CACHE = new Map();

	function requestJson( url, data ) {
		const headers = {
			'Content-Type': 'application/json'
		};
		const config = window.HandikBookingAppConfig;
		if ( config && config.restNonce ) {
			headers['X-WP-Nonce'] = config.restNonce;
		}
		return window.fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: headers,
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

	function sanitizeNumber( value ) {
		const number = Number( value );
		return Number.isFinite( number ) && number > 0 ? number : 0;
	}

	function normalizeResult( input ) {
		const value = input && typeof input === 'object' ? input : {};
		const allowed = [ 'standard_visit', 'extended_visit', 'large_visit', 'project_consultation' ];
		const suggestedDurationAllowed = [ '1', '2', '3', '4', '5', '6', '7', '8', 'consult_1' ];
		const pricingAllowed = [ 'hourly_only', 'hourly_plus_materials', 'consultation_first' ];
		const result = {
			service_family: sanitizeKey( value.service_family ),
			rate_family: sanitizeKey( value.rate_family ),
			duration_bucket: sanitizeKey( value.duration_bucket ),
			booking_type: allowed.includes( value.booking_type ) ? value.booking_type : '',
			suggested_duration_hours: suggestedDurationAllowed.includes( String( value.suggested_duration_hours || '' ) ) ? String( value.suggested_duration_hours ) : '',
			pricing_posture: pricingAllowed.includes( String( value.pricing_posture || '' ) ) ? String( value.pricing_posture ) : '',
			assistant_summary: sanitizeText( value.assistant_summary ),
			estimate_notes: sanitizeText( value.estimate_notes ),
			enough_information: Boolean( value.enough_information ),
			unsafe: Boolean( value.unsafe ),
			unsafe_reason: sanitizeText( value.unsafe_reason ),
			is_project: Boolean( value.is_project ),
			next_message: sanitizeText( value.next_message ),
			selected_task_mismatch: Boolean( value.selected_task_mismatch ),
			mismatch_notes: sanitizeText( value.mismatch_notes )
		};
		[ 'applied_hourly_rate', 'labor_estimate_low', 'labor_estimate_high', 'materials_estimate_low', 'materials_estimate_high', 'total_estimate_low', 'total_estimate_high' ].forEach( function( key ) {
			if ( Object.prototype.hasOwnProperty.call( value, key ) ) {
				result[ key ] = sanitizeNumber( value[ key ] );
			}
		} );
		[ 'materials_notes', 'estimate_disclaimer' ].forEach( function( key ) {
			if ( Object.prototype.hasOwnProperty.call( value, key ) ) {
				result[ key ] = sanitizeText( value[ key ] );
			}
		} );
		return result;
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
				Object.prototype.hasOwnProperty.call( value, 'next_message' ) ||
				Object.prototype.hasOwnProperty.call( value, 'suggested_duration_hours' ) ||
				Object.prototype.hasOwnProperty.call( value, 'pricing_posture' ) ||
				Object.prototype.hasOwnProperty.call( value, 'total_estimate_low' ) ||
				Object.prototype.hasOwnProperty.call( value, 'total_estimate_high' )
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
			if ( cachedRecord.ready && typeof options.onSessionReady === 'function' ) {
				options.onSessionReady( cachedRecord.session || null );
			}
			if ( cachedRecord.latestThreadId && typeof options.onThreadChange === 'function' ) {
				options.onThreadChange( cachedRecord.latestThreadId );
			}
			return {
				element: function() {
					return cachedRecord.element;
				},
				ready: Promise.resolve( cachedRecord.session || null ),
				sendContextMessage: function( text ) {
					if ( ! cachedRecord.element || 'function' !== typeof cachedRecord.element.sendUserMessage || ! text ) {
						return Promise.resolve( false );
					}
					return Promise.resolve( cachedRecord.element.sendUserMessage( { text: text } ) ).then( function() {
						return true;
					} );
				},
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
			sessionReadyFired: false,
			element: null,
			session: null,
			lastStructuredPayload: null,
			cachedSession: null
		};

		// Sprint 1 A3 (revised): seed only cachedSession so the first
		// getClientSecret() returns synchronously. Do NOT prefill record.session
		// — that field is the canonical session-ready signal and prefilling it
		// makes markChatActive emit session-ready prematurely while the
		// ChatKit element is still booting. v2.1.8.9 saw infinite-loading
		// regressions because of that. The real session lands in
		// record.session a few ms later when waitForChatKitElement().then(...)
		// resolves.
		if ( options.prewarmedSession && typeof options.prewarmedSession === 'object' ) {
			record.cachedSession = options.prewarmedSession;
		}

		BRIDGE_CACHE.set( cacheKey, record );

		const emitStatus = function( text, context ) {
			if ( typeof record.options.onStatus === 'function' ) {
				record.options.onStatus( text, context || {} );
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

			if ( endpoints.clientLog && 'debug' !== payload.level ) {
				requestJson( endpoints.clientLog, payload ).catch( function() {} );
			}
		};

		// --- B3: chat transcript mirror ----------------------------------
		// Send each user/assistant message we can detect to the admin's
		// /messages/record endpoint so the booking detail can later show a
		// real transcript instead of grepping plugin logs.
		const recordedMessageHashes = new Set();
		function shortHash( role, text ) {
			let h = 5381;
			const input = role + '' + text;
			for ( let i = 0; i < input.length; i++ ) {
				h = ( h * 33 ) ^ input.charCodeAt( i );
			}
			return ( h >>> 0 ).toString( 36 );
		}
		function extractMessageText( detail ) {
			if ( ! detail || 'object' !== typeof detail ) {
				return '';
			}
			const candidates = [
				detail.content,
				detail.text,
				detail.message,
				detail.body,
				detail.data && detail.data.content,
				detail.data && detail.data.text,
				detail.data && detail.data.message,
				Array.isArray( detail.content ) && detail.content.map( function( part ) {
					return part && typeof part === 'object' ? ( part.text || part.content || '' ) : String( part || '' );
				} ).join( ' ' )
			];
			for ( const candidate of candidates ) {
				if ( typeof candidate === 'string' && candidate.trim() ) {
					return candidate.trim();
				}
			}
			return '';
		}
		function recordMessage( role, content, metadata ) {
			if ( ! content ) {
				return;
			}
			const config = window.HandikBookingAppConfig;
			if ( ! config || ! config.restBase || ! record.options.requestId || ! record.options.draftToken ) {
				return;
			}
			const text = String( content ).slice( 0, 16000 );
			const hash = shortHash( role || 'unknown', text );
			if ( recordedMessageHashes.has( hash ) ) {
				return;
			}
			recordedMessageHashes.add( hash );
			requestJson( config.restBase + 'messages/record', {
				request_id:  record.options.requestId,
				draft_token: record.options.draftToken,
				role:        role || 'user',
				content:     text,
				thread_id:   record.latestThreadId || record.options.threadId || '',
				metadata:    metadata || {}
			} ).then( function() {
				log( 'debug', 'Mirrored chat message to admin.', { role: role, length: text.length } );
			} ).catch( function( error ) {
				log( 'debug', 'Failed to mirror chat message.', { role: role, error: summarizeError( error ) } );
			} );
		}

		const saveStructuredResult = function( result, source ) {
			const normalized = normalizeResult( result );
			const signature = JSON.stringify( normalized );
			if ( record.handledSignature === signature ) {
				log( 'debug', 'Structured result skipped as duplicate.', { source: source || 'unknown' } );
				// 2.1.29.1 P0 fix: skip the server round-trip on a duplicate
				// payload, but STILL notify the caller that the assistant
				// turn ended. Without this, follow-up turns where the model
				// returns the same booking_type/duration leave the booking-app
				// SPA's per-turn status block running until 50s and never
				// re-enable the booking CTA (assistantReadyForBooking is set
				// inside applySavedAssistantRouting → onComplete).
				const cached = record.lastStructuredPayload;
				if ( cached ) {
					try {
						if ( typeof record.options.onStructuredResult === 'function' ) {
							record.options.onStructuredResult( normalized, cached );
						}
						if ( typeof record.options.onComplete === 'function' ) {
							record.options.onComplete( normalized, cached );
						}
					} catch ( callbackError ) {
						log( 'error', 'onComplete re-fire on duplicate structured result threw.', { source: source || 'unknown', error: summarizeError( callbackError ) } );
					}
				}
				return Promise.resolve( cached || {
					success: true,
					assistant_result: normalized,
					routing: normalized
				} );
			}
			record.handledSignature = signature;
			log( 'info', 'Structured result captured.', {
				source: source || 'unknown',
				booking_type: normalized.booking_type,
				duration_bucket: normalized.duration_bucket,
				suggested_duration_hours: normalized.suggested_duration_hours,
				pricing_posture: normalized.pricing_posture,
				applied_hourly_rate: normalized.applied_hourly_rate,
				total_estimate_low: normalized.total_estimate_low,
				total_estimate_high: normalized.total_estimate_high,
				unsafe: normalized.unsafe
			} );

			return requestJson( endpoints.saveAssistantResult, {
				request_id: record.options.requestId,
				draft_token: record.options.draftToken,
				assistant_result: normalized
			} ).then( function( payload ) {
				record.lastStructuredPayload = payload || null;
				log( 'info', 'Structured result stored.', {
					source: source || 'unknown',
					unsafe_flag: payload.unsafe_flag || false,
					booking_type: payload && payload.routing ? payload.routing.booking_type || '' : '',
					duration_bucket: payload && payload.routing ? payload.routing.duration_bucket || '' : '',
					suggested_duration_hours: payload && payload.routing ? payload.routing.suggested_duration_hours || '' : '',
					booking_url: payload && payload.booking_url ? payload.booking_url : ''
				} );
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

		const fallbackToolResponse = function( message ) {
			return {
				ok: false,
				has_photos: false,
				has_actionable_visual_context: false,
				photo_context_summary: '',
				visible_tasks_summary: '',
				safety_summary: '',
				visual_estimate_notes: '',
				missing_visual_details: [ sanitizeText( message || 'Photo context unavailable.' ) ]
			};
		};

		const fallbackPricingToolResponse = function( message ) {
			return {
				ok: false,
				has_pricing_context: false,
				selected_tasks: [],
				selected_task_count: 0,
				applied_hourly_rate: 0,
				applied_rate_source: 'none',
				suggested_duration_hours: '',
				duration_low_hours: 0,
				duration_high_hours: 0,
				labor_estimate_low: 0,
				labor_estimate_high: 0,
				materials_estimate_low: 0,
				materials_estimate_high: 0,
				total_estimate_low: 0,
				total_estimate_high: 0,
				materials_notes: '',
				estimate_disclaimer: sanitizeText( message || 'Pricing context unavailable.' )
			};
		};

		const handleClientTool = function( toolCall ) {
			const name = toolCall && toolCall.name ? String( toolCall.name ) : '';
			const rawParams = toolCall && toolCall.params && 'object' === typeof toolCall.params ? toolCall.params : ( toolCall && toolCall.arguments && 'object' === typeof toolCall.arguments ? toolCall.arguments : ( toolCall && toolCall.input && 'object' === typeof toolCall.input ? toolCall.input : {} ) );
			const params = rawParams && rawParams.params && 'object' === typeof rawParams.params ? rawParams.params : rawParams;
			log( 'info', 'ChatKit client tool invoked.', {
				name: name,
				params: params
			} );

			if ( 'get_request_photo_context' === name ) {
				if ( ! endpoints.requestPhotoContext ) {
					log( 'error', 'ChatKit client tool endpoint missing.', { name: name } );
					return Promise.resolve( fallbackToolResponse( 'Photo context endpoint is not configured.' ) );
				}

				log( 'info', 'ChatKit client tool fetch started.', {
					name: name,
					request_id: record.options.requestId
				} );
				return requestJson( endpoints.requestPhotoContext, {
					request_id: record.options.requestId,
					draft_token: record.options.draftToken,
					tool_params: params
				} ).then( function( payload ) {
					log( 'info', 'ChatKit client tool fetch completed.', {
						name: name,
						ok: !! payload.success,
						has_photos: !! payload.has_photos,
						photo_analysis_status: payload.photo_analysis_status || ''
					} );
					const responsePayload = Object.assign(
						{
							ok: true
						},
						payload || {}
					);
					log( 'info', 'ChatKit client tool completed.', {
						name: name,
						has_photos: !! responsePayload.has_photos,
						photo_analysis_status: responsePayload.photo_analysis_status || '',
						has_actionable_visual_context: !! responsePayload.has_actionable_visual_context
					} );
					log( 'info', 'ChatKit client tool returning payload.', {
						name: name,
						keys: Object.keys( responsePayload )
					} );
					return responsePayload;
				} ).catch( function( error ) {
					log( 'error', 'ChatKit client tool failed.', {
						name: name,
						error: summarizeError( error )
					} );
					return fallbackToolResponse( summarizeError( error ) );
				} );
			}

			if ( 'get_request_pricing_context' === name ) {
				if ( ! endpoints.requestPricingContext ) {
					log( 'error', 'ChatKit client tool endpoint missing.', { name: name } );
					return Promise.resolve( fallbackPricingToolResponse( 'Pricing context endpoint is not configured.' ) );
				}

				log( 'info', 'ChatKit pricing context tool fetch started.', {
					name: name,
					request_id: record.options.requestId
				} );
				return requestJson( endpoints.requestPricingContext, {
					request_id: record.options.requestId,
					draft_token: record.options.draftToken,
					tool_params: params
				} ).then( function( payload ) {
					log( 'info', 'ChatKit pricing context tool fetch completed.', {
						name: name,
						ok: !! payload.success,
						has_pricing_context: !! payload.has_pricing_context,
						applied_hourly_rate: payload.applied_hourly_rate || 0,
						total_estimate_low: payload.total_estimate_low || 0,
						total_estimate_high: payload.total_estimate_high || 0
					} );
					const responsePayload = Object.assign(
						{
							ok: true
						},
						payload || {}
					);
					log( 'info', 'ChatKit pricing context tool returning payload.', {
						name: name,
						keys: Object.keys( responsePayload )
					} );
					return responsePayload;
				} ).catch( function( error ) {
					log( 'error', 'ChatKit pricing context tool failed.', {
						name: name,
						error: summarizeError( error )
					} );
					return fallbackPricingToolResponse( summarizeError( error ) );
				} );
			}

			if ( 'save_assistant_routing_result' === name ) {
				if ( ! endpoints.saveAssistantResult ) {
					log( 'error', 'ChatKit client tool endpoint missing.', { name: name } );
					return Promise.resolve( {
						ok: false,
						error: 'Assistant result endpoint is not configured.'
					} );
				}

				if ( ! looksLikeStructuredResult( params ) || ! params.booking_type || ! params.duration_bucket || ! params.suggested_duration_hours ) {
					log( 'error', 'ChatKit routing result tool received empty or incomplete payload.', {
						name: name,
						request_id: record.options.requestId,
						keys: params && 'object' === typeof params ? Object.keys( params ) : []
					} );
					return Promise.resolve( {
						ok: false,
						success: false,
						assistant_result_saved: false,
						error: 'save_assistant_routing_result requires the full routing payload.'
					} );
				}

				log( 'info', 'ChatKit routing result tool save started.', {
					name: name,
					request_id: record.options.requestId
				} );

				return saveStructuredResult( params, 'client_tool:' + name ).then( function( payload ) {
					const routing = payload && payload.routing && 'object' === typeof payload.routing ? payload.routing : {};
					const assistantResult = payload && payload.assistant_result && 'object' === typeof payload.assistant_result ? payload.assistant_result : {};
					const responsePayload = {
						ok: !! ( payload && payload.assistant_result_saved ),
						success: !! ( payload && payload.success ),
						assistant_result_saved: !! ( payload && payload.assistant_result_saved ),
						assistant_ready_for_booking: !! ( payload && payload.assistant_ready_for_booking ),
						booking_type: routing.booking_type || assistantResult.booking_type || '',
						duration_bucket: routing.duration_bucket || assistantResult.duration_bucket || '',
						suggested_duration_hours: routing.suggested_duration_hours || assistantResult.suggested_duration_hours || '',
						pricing_posture: routing.pricing_posture || assistantResult.pricing_posture || '',
						enough_information: !! assistantResult.enough_information,
						next_message: assistantResult.next_message || '',
						applied_hourly_rate: assistantResult.applied_hourly_rate || 0,
						labor_estimate_low: assistantResult.labor_estimate_low || 0,
						labor_estimate_high: assistantResult.labor_estimate_high || 0,
						materials_estimate_low: assistantResult.materials_estimate_low || 0,
						materials_estimate_high: assistantResult.materials_estimate_high || 0,
						total_estimate_low: assistantResult.total_estimate_low || 0,
						total_estimate_high: assistantResult.total_estimate_high || 0,
						materials_notes: assistantResult.materials_notes || '',
						estimate_disclaimer: assistantResult.estimate_disclaimer || '',
						selected_task_mismatch: !! assistantResult.selected_task_mismatch,
						mismatch_notes: assistantResult.mismatch_notes || '',
						unsafe_flag: !! ( payload && payload.unsafe_flag ),
						unsafe_reason: payload && payload.unsafe_reason ? String( payload.unsafe_reason ) : '',
						booking_url_ready: !! ( payload && payload.booking_url_ready && payload.booking_url )
					};
					log( 'info', 'ChatKit routing result tool save completed.', {
						name: name,
						booking_type: responsePayload.booking_type,
						duration_bucket: responsePayload.duration_bucket,
						suggested_duration_hours: responsePayload.suggested_duration_hours,
						total_estimate_low: responsePayload.total_estimate_low,
						total_estimate_high: responsePayload.total_estimate_high,
						booking_url_ready: responsePayload.booking_url_ready,
						assistant_ready_for_booking: responsePayload.assistant_ready_for_booking
					} );
					return responsePayload;
				} ).catch( function( error ) {
					log( 'error', 'ChatKit routing result tool save failed.', {
						name: name,
						error: summarizeError( error )
					} );
					return {
						ok: false,
						success: false,
						assistant_result_saved: false,
						error: summarizeError( error )
					};
				} );
			}

			throw new Error( 'Unhandled client tool: ' + name );
		};

		const emitSessionReady = function( source ) {
			if ( record.sessionReadyFired ) {
				return;
			}
			record.sessionReadyFired = true;
			log( 'info', 'ChatKit session-ready callback fired.', { source: source || 'unknown' } );
			if ( typeof record.options.onSessionReady === 'function' ) {
				record.options.onSessionReady( record.session || null );
			}
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
			if ( record.session ) {
				emitSessionReady( source || 'interactive' );
			}
		};

		const buildOptions = function() {
			const uploadConfig = record.session && record.session.file_upload && 'object' === typeof record.session.file_upload
				? record.session.file_upload
				: null;
			const maxCount = uploadConfig && uploadConfig.max_files ? Number( uploadConfig.max_files ) : 5;
			const maxSizeMb = uploadConfig && uploadConfig.max_file_size ? Number( uploadConfig.max_file_size ) : 10;
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
					placeholder: record.options.composerPlaceholder || undefined,
					attachments: {
						enabled: false,
						accept: {
							'image/*': [ '.jpg', '.jpeg', '.png', '.webp', '.heic', '.heif' ]
						},
						maxCount: maxCount > 0 ? maxCount : 5,
						maxSize: maxSizeMb > 0 ? maxSizeMb * 1024 * 1024 : ( 10 * 1024 * 1024 )
					}
				},
				onClientTool: handleClientTool
			};
		};

		const applyOptions = function() {
			if ( ! record.element ) {
				return;
			}

			const nextOptions = buildOptions();
			record.element.onClientTool = handleClientTool;
			record.element.options = nextOptions;
			if ( 'function' === typeof record.element.setOptions ) {
				record.element.setOptions( nextOptions );
			}
			log( 'debug', 'ChatKit options applied.', {
				has_on_client_tool: 'function' === typeof nextOptions.onClientTool,
				has_element_on_client_tool: 'function' === typeof record.element.onClientTool
			} );
		};

		options.container.innerHTML = '';
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
				has_file_upload: !! session.file_upload,
				file_upload_config: session.file_upload || null
			} );

			record.element = document.createElement( CHATKIT_TAG );
			record.element.style.display = 'block';
			record.element.style.width = '100%';
			record.element.style.height = '100%';
			record.element.style.minHeight = '100%';

			record.element.addEventListener( 'chatkit.ready', function() {
				record.ready = true;
				record.interactive = true;
				if ( record.readyTimer ) {
					window.clearTimeout( record.readyTimer );
				}
				log( 'info', 'ChatKit ready event fired.' );
				emitSessionReady( 'chatkit.ready' );
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
						attachmentsCount: detail.data && detail.data.attachmentsCount ? detail.data.attachmentsCount : 0
					} );
				}
				if ( 'composer.submit' === detail.name ) {
					// detail.data here usually holds the user-typed string (or an array
					// of content parts). recordMessage() de-dupes hashes so an extra
					// fire of message-event later won't double-record.
					const userText = extractMessageText( detail.data || detail );
					if ( userText ) {
						recordMessage( 'user', userText, { source: 'composer.submit' } );
					}
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
				// This is a CustomEvent fired by the same-origin <openai-chatkit> web component,
				// not a cross-origin window.postMessage. Still, guard against synthetic events
				// dispatched by other scripts on the page by verifying the source target.
				if ( event && event.target !== record.element ) {
					return;
				}
				const detail = event && event.detail ? event.detail : {};
				markChatActive( 'message' );
				log( 'debug', 'ChatKit message event.', {
					message_type: detail.type || '',
					role: detail.role || ''
				} );
				if ( typeof record.options.onMessageActivity === 'function' ) {
					record.options.onMessageActivity( detail );
				}
				const messageRole = ( detail.role === 'assistant' || detail.role === 'user' || detail.role === 'system' ) ? detail.role : '';
				if ( messageRole ) {
					const messageText = extractMessageText( detail );
					if ( messageText ) {
						recordMessage( messageRole, messageText, { source: 'chatkit.message', type: detail.type || '' } );
					}
				}
				const structured = extractStructuredResult( detail );
				if ( structured ) {
					saveStructuredResult( structured, 'message' );
				}
			} );

			options.container.innerHTML = '';
			options.container.appendChild( record.element );
			applyOptions();
			log( 'info', 'ChatKit mounted into container.' );

			record.readyTimer = window.setTimeout( function() {
				if ( ! record.ready && ! record.interactive ) {
					log( 'debug', 'ChatKit ready timeout reached without a visible UI error.', { timeout_ms: CHATKIT_TIMEOUT } );
				} else if ( record.session && ! record.sessionReadyFired ) {
					log( 'debug', 'ChatKit ready timeout reached after interactive mount; forcing session-ready callback.', { timeout_ms: CHATKIT_TIMEOUT } );
					emitSessionReady( 'timeout' );
				}
			}, CHATKIT_TIMEOUT );

			return session;
		} ).catch( handleMountError );

		return {
			element: function() {
				return record.element;
			},
			ready: ready,
			sendContextMessage: function( text ) {
				return ready.then( function() {
					if ( ! record.element || 'function' !== typeof record.element.sendUserMessage || ! text ) {
						return false;
					}
					return Promise.resolve( record.element.sendUserMessage( { text: text } ) ).then( function() {
						return true;
					} );
				} );
			},
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
