/**
 * Handik Booking App — admin JS (v2.1.8.2).
 *
 * Modules:
 *   - Toast / modal helpers (F2)
 *   - Booking detail actions (B4)
 *   - Person edit + address management (C3)
 *   - Add-person form (C3)
 *   - Catalog editor with SortableJS + auto-save (D2)
 *   - System tools (D5)
 *   - Photo lightbox (B2)
 *   - Bookings list table-vs-card switch + debounced search (B1)
 *   - Copy-on-tap helpers
 */
( function( window, document ) {
	'use strict';

	const config = window.HandikAdmin || {};
	const i18n = config.i18n || {};

	// ============================================================
	// REST helper
	// ============================================================

	function adminFetch( ctx, path, options ) {
		const opts = options || {};
		const base = ctx && ctx.dataset.restBase ? ctx.dataset.restBase : config.restBase;
		const nonce = ctx && ctx.dataset.restNonce ? ctx.dataset.restNonce : config.restNonce;
		const headers = { 'X-WP-Nonce': nonce };
		const init = {
			method: opts.method || 'POST',
			credentials: 'same-origin',
			headers: headers
		};
		if ( opts.body ) {
			headers['Content-Type'] = 'application/json';
			init.body = JSON.stringify( opts.body );
		}
		return window.fetch( base + path, init ).then( function( response ) {
			return response.json().catch( function() { return {}; } ).then( function( payload ) {
				if ( ! response.ok ) {
					const error = new Error( payload.message || payload.code || 'Request failed' );
					error.status = response.status;
					throw error;
				}
				return payload;
			} );
		} );
	}

	// ============================================================
	// Button loading state (F2)
	// ============================================================

	function withButtonLoading( btn, fn ) {
		if ( ! btn ) { return Promise.resolve( fn() ); }
		const original = btn.innerHTML;
		btn.disabled = true;
		btn.classList.add( 'is-loading' );
		btn.innerHTML = '<span class="handik-admin-spinner" aria-hidden="true"></span> ' + ( i18n.placeholder || '…' );
		return Promise.resolve()
			.then( fn )
			.finally( function() {
				btn.disabled = false;
				btn.classList.remove( 'is-loading' );
				btn.innerHTML = original;
			} );
	}

	// ============================================================
	// Toast
	// ============================================================

	let toastsRoot = null;
	function ensureToastsRoot() {
		if ( toastsRoot ) {
			return toastsRoot;
		}
		toastsRoot = document.createElement( 'div' );
		toastsRoot.className = 'handik-admin-toasts';
		toastsRoot.setAttribute( 'role', 'status' );
		toastsRoot.setAttribute( 'aria-live', 'polite' );
		document.body.appendChild( toastsRoot );
		return toastsRoot;
	}

	function toast( message, tone, duration ) {
		const root = ensureToastsRoot();
		const node = document.createElement( 'div' );
		node.className = 'handik-admin-toast handik-admin-toast--' + ( tone || 'info' );
		node.textContent = message;
		root.appendChild( node );
		const ttl = ( duration || 2800 );
		window.setTimeout( function() {
			node.style.transition = 'opacity 200ms ease';
			node.style.opacity = '0';
			window.setTimeout( function() {
				if ( node.parentNode ) {
					node.parentNode.removeChild( node );
				}
			}, 220 );
		}, ttl );
	}

	// ============================================================
	// Modal
	// ============================================================

	// Sprint 7 (a11y): shared focus trap. WCAG 2.4.3 — when a modal is open,
	// Tab and Shift+Tab must cycle within the dialog and never escape into the
	// underlying admin DOM. The trap also remembers the previously focused
	// element so we can restore focus when the modal closes (so keyboard
	// users don't snap to the top of the page).
	//
	// 2.1.15.1 audit fix (P1 #6): a single module-scoped stack so nested
	// modals don't double-handle Tab. Only the topmost trap responds; older
	// traps (still in the stack until their owner releases) sit dormant.
	// 2.1.15.1 audit fix (P2 #7): on release, only restore focus to the
	// previously focused element if it's still in the live DOM — the
	// trigger button may have been re-rendered while the dialog was open.
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
			// Only the topmost trap on the stack is active.
			if ( __handikModalTrapStack[ __handikModalTrapStack.length - 1 ] !== trap ) { return; }
			const list = getFocusable();
			if ( ! list.length ) { event.preventDefault(); container.focus(); return; }
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

	/**
	 * Sprint 12 — typed-confirmation modal for destructive cascades.
	 *
	 * Click-confirm modals are too easy to mash through on mobile, so
	 * hard-delete actions (Person / Request / Booking) require the
	 * operator to type the literal string "DELETE" before the
	 * destructive button activates. Resolves to `true` on confirm,
	 * `null` on cancel/escape — same shape openModal() uses, so the
	 * caller can `await` without a special branch.
	 *
	 * Options:
	 *   title        — dialog title.
	 *   body         — descriptive paragraph.
	 *   preview      — optional second paragraph listing what gets
	 *                  swept (e.g. "3 requests, 2 bookings…").
	 *   confirmLabel — destructive CTA label.
	 *   token        — string the operator has to type (default
	 *                  "DELETE").
	 */
	function openTypedConfirmModal( opts ) {
		opts = opts || {};
		const token = String( opts.token || 'DELETE' );
		return new Promise( function ( resolve ) {
			const backdrop = document.createElement( 'div' );
			backdrop.className = 'handik-admin-modal-backdrop';
			const modal = document.createElement( 'div' );
			modal.className = 'handik-admin-modal handik-admin-modal--danger';
			modal.setAttribute( 'role', 'dialog' );
			modal.setAttribute( 'aria-modal', 'true' );

			const title = document.createElement( 'h3' );
			title.textContent = opts.title || 'Permanent delete';
			modal.appendChild( title );

			const body = document.createElement( 'div' );
			body.className = 'handik-admin-modal__body';
			if ( opts.body ) {
				const p = document.createElement( 'p' );
				p.textContent = opts.body;
				body.appendChild( p );
			}
			if ( opts.preview ) {
				const p2 = document.createElement( 'p' );
				p2.className = 'handik-admin-muted';
				p2.innerHTML = '<strong>' + String( opts.preview ).replace( /[<>&]/g, function ( c ) {
					return { '<': '&lt;', '>': '&gt;', '&': '&amp;' }[ c ];
				} ) + '</strong>';
				body.appendChild( p2 );
			}
			const instruction = document.createElement( 'p' );
			instruction.innerHTML = ( i18n.typedConfirmInstruction || 'Type %s to confirm. This is irreversible.' )
				.replace( '%s', '<code>' + token + '</code>' );
			body.appendChild( instruction );

			const input = document.createElement( 'input' );
			input.type = 'text';
			input.autocomplete = 'off';
			input.spellcheck = false;
			input.className = 'handik-admin-modal__typed-confirm';
			input.setAttribute( 'aria-label', ( i18n.typedConfirmAria || 'Type %s to enable delete' ).replace( '%s', token ) );
			body.appendChild( input );
			modal.appendChild( body );

			const actions = document.createElement( 'div' );
			actions.className = 'handik-admin-modal__actions';
			const cancel = document.createElement( 'button' );
			cancel.type = 'button';
			cancel.className = 'button';
			cancel.textContent = opts.cancelLabel || i18n.cancel || 'Cancel';
			const confirm = document.createElement( 'button' );
			confirm.type = 'button';
			confirm.className = 'button button-link-delete';
			confirm.textContent = opts.confirmLabel || i18n.delete || 'Delete';
			confirm.disabled = true;
			actions.appendChild( cancel );
			actions.appendChild( confirm );
			modal.appendChild( actions );
			backdrop.appendChild( modal );

			let releaseFocusTrap = function () {};
			function close( value ) {
				releaseFocusTrap();
				if ( backdrop.parentNode ) { backdrop.parentNode.removeChild( backdrop ); }
				document.removeEventListener( 'keydown', onKey, true );
				resolve( value );
			}
			function onKey( event ) {
				if ( 'Escape' === event.key ) { close( null ); }
			}
			input.addEventListener( 'input', function () {
				confirm.disabled = input.value !== token;
			} );
			input.addEventListener( 'keydown', function ( event ) {
				if ( 'Enter' === event.key && ! confirm.disabled ) {
					event.preventDefault();
					close( true );
				}
			} );
			document.addEventListener( 'keydown', onKey, true );
			cancel.addEventListener( 'click', function () { close( null ); } );
			confirm.addEventListener( 'click', function () { close( true ); } );
			backdrop.addEventListener( 'click', function ( event ) {
				if ( event.target === backdrop ) { close( null ); }
			} );

			document.body.appendChild( backdrop );
			releaseFocusTrap = trapModalFocus( modal );
			window.setTimeout( function () { input.focus(); }, 0 );
		} );
	}

	function openModal( opts ) {
		opts = opts || {};
		return new Promise( function( resolve ) {
			const backdrop = document.createElement( 'div' );
			backdrop.className = 'handik-admin-modal-backdrop';

			const modal = document.createElement( 'div' );
			modal.className = 'handik-admin-modal';
			modal.setAttribute( 'role', 'dialog' );
			modal.setAttribute( 'aria-modal', 'true' );

			const title = document.createElement( 'h3' );
			title.textContent = opts.title || 'Confirm';
			modal.appendChild( title );

			const body = document.createElement( 'div' );
			body.className = 'handik-admin-modal__body';

			let textarea = null;
			let textInput = null;
			if ( opts.body ) {
				const p = document.createElement( 'p' );
				p.textContent = opts.body;
				body.appendChild( p );
			}
			if ( opts.textarea ) {
				textarea = document.createElement( 'textarea' );
				textarea.placeholder = opts.placeholder || i18n.placeholder || '';
				textarea.value = opts.defaultValue || '';
				body.appendChild( textarea );
			} else if ( opts.input ) {
				// 2.1.26.4 — single-line text input for type-to-confirm
				// modals (bulk-delete safety). User must type a token
				// matching `opts.placeholder` exactly before the
				// caller treats the modal result as confirmation.
				textInput = document.createElement( 'input' );
				textInput.type = 'text';
				textInput.placeholder = opts.placeholder || '';
				textInput.value = opts.defaultValue || '';
				textInput.className = 'handik-admin-modal__input';
				textInput.autocomplete = 'off';
				textInput.spellcheck = false;
				body.appendChild( textInput );
			}
			modal.appendChild( body );

			const actions = document.createElement( 'div' );
			actions.className = 'handik-admin-modal__actions';

			const cancel = document.createElement( 'button' );
			cancel.type = 'button';
			cancel.className = 'button';
			cancel.textContent = opts.cancelLabel || i18n.cancel || 'Cancel';
			cancel.addEventListener( 'click', function() {
				close( null );
			} );

			const confirm = document.createElement( 'button' );
			confirm.type = 'button';
			confirm.className = 'button button-primary';
			confirm.textContent = opts.confirmLabel || i18n.confirm || 'Confirm';
			confirm.addEventListener( 'click', function() {
				if ( textarea ) {
					close( textarea.value );
				} else if ( textInput ) {
					close( textInput.value );
				} else {
					close( true );
				}
			} );

			actions.appendChild( cancel );
			actions.appendChild( confirm );
			modal.appendChild( actions );
			backdrop.appendChild( modal );

			let releaseFocusTrap = function () {};
			function close( value ) {
				releaseFocusTrap();
				if ( backdrop.parentNode ) {
					backdrop.parentNode.removeChild( backdrop );
				}
				document.removeEventListener( 'keydown', onKey, true );
				resolve( value );
			}

			function onKey( event ) {
				if ( 'Escape' === event.key ) {
					close( null );
				}
			}
			document.addEventListener( 'keydown', onKey, true );
			backdrop.addEventListener( 'click', function( event ) {
				if ( event.target === backdrop ) {
					close( null );
				}
			} );

			document.body.appendChild( backdrop );
			releaseFocusTrap = trapModalFocus( modal );
			window.setTimeout( function() {
				if ( textarea ) {
					textarea.focus();
				} else {
					confirm.focus();
				}
			}, 0 );
		} );
	}

	function openAddressEditModal( values ) {
		// Custom modal w/ several inputs. Returns Promise<{label, address_full, ...}|null>
		return new Promise( function( resolve ) {
			const backdrop = document.createElement( 'div' );
			backdrop.className = 'handik-admin-modal-backdrop';
			backdrop.innerHTML =
				'<div class="handik-admin-modal" role="dialog" aria-modal="true">' +
					'<h3>' + ( i18n.addressEdit || 'Edit address' ) + '</h3>' +
					'<div class="handik-admin-modal__body">' +
						'<label class="handik-admin-field"><span>Label (e.g. Home)</span><input type="text" data-field="label" value="' + escapeAttr( values.label ) + '" /></label>' +
						'<label class="handik-admin-field"><span>Full address</span><input type="text" data-field="address_full" autocomplete="street-address" value="' + escapeAttr( values.address_full ) + '" /></label>' +
						'<label class="handik-admin-field"><span>Unit / apt</span><input type="text" data-field="address_unit" autocomplete="address-line2" value="' + escapeAttr( values.address_unit ) + '" /></label>' +
						'<div class="handik-admin-grid">' +
							'<label class="handik-admin-field"><span>City</span><input type="text" data-field="city" autocomplete="address-level2" value="' + escapeAttr( values.city ) + '" /></label>' +
							'<label class="handik-admin-field"><span>State</span><input type="text" data-field="state" autocomplete="address-level1" value="' + escapeAttr( values.state ) + '" /></label>' +
							'<label class="handik-admin-field"><span>ZIP</span><input type="text" data-field="zip_code" autocomplete="postal-code" inputmode="numeric" value="' + escapeAttr( values.zip_code ) + '" /></label>' +
						'</div>' +
					'</div>' +
					'<div class="handik-admin-modal__actions">' +
						'<button type="button" class="button" data-action="cancel">' + ( i18n.cancel || 'Cancel' ) + '</button>' +
						'<button type="button" class="button button-primary" data-action="save">' + ( i18n.save || 'Save' ) + '</button>' +
					'</div>' +
				'</div>';
			let releaseFocusTrap = function () {};
			function close( result ) {
				releaseFocusTrap();
				if ( backdrop.parentNode ) { backdrop.parentNode.removeChild( backdrop ); }
				document.removeEventListener( 'keydown', onKey, true );
				resolve( result );
			}
			function onKey( event ) { if ( 'Escape' === event.key ) { close( null ); } }
			document.addEventListener( 'keydown', onKey, true );
			backdrop.addEventListener( 'click', function( event ) {
				if ( event.target === backdrop ) { close( null ); return; }
				const btn = event.target.closest( '[data-action]' );
				if ( ! btn ) { return; }
				if ( 'cancel' === btn.dataset.action ) {
					close( null );
				}
				if ( 'save' === btn.dataset.action ) {
					const out = {};
					backdrop.querySelectorAll( '[data-field]' ).forEach( function( input ) {
						out[ input.dataset.field ] = input.value;
					} );
					close( out );
				}
			} );
			document.body.appendChild( backdrop );
			releaseFocusTrap = trapModalFocus( backdrop.querySelector( '.handik-admin-modal' ) );
			window.setTimeout( function() {
				const first = backdrop.querySelector( '[data-field="address_full"]' );
				if ( first ) { first.focus(); }
			}, 0 );
		} );
	}

	function escapeAttr( s ) {
		return String( s == null ? '' : s ).replace( /[&"<>]/g, function( c ) {
			return ( { '&': '&amp;', '"': '&quot;', '<': '&lt;', '>': '&gt;' } )[ c ];
		} );
	}

	// ============================================================
	// Booking detail actions (B4)
	// ============================================================

	function initBookingActions() {
		document.querySelectorAll( '[data-handik-booking-actions]' ).forEach( function( ctx ) {
			const bookingId = ctx.dataset.bookingId;
			ctx.addEventListener( 'click', async function( event ) {
				const target = event.target.closest( '[data-handik-action]' );
				if ( ! target ) {
					return;
				}
				event.preventDefault();
				const action = target.dataset.handikAction;
				try {
					if ( 'add-note' === action ) {
						const value = await openModal( { title: i18n.noteTitle || 'Add note', textarea: true } );
						if ( null === value ) { return; }
						await adminFetch( ctx, 'admin/booking/' + bookingId + '/notes', { body: { admin_notes: value } } );
						toast( i18n.saved || 'Saved', 'success' );
						const display = document.querySelector( '[data-handik-admin-notes-display]' );
						if ( display ) {
							display.textContent = value;
						}
					}
					if ( 'mark-cancelled' === action ) {
						// 2.1.27.0 — prompt for an optional cancellation
						// reason. The server forwards it to Cal.com as the
						// cancellation message; the customer sees it in
						// their cancel-notification email + calendar
						// invite update (Apple, Google, etc.). Empty
						// reason → server defaults to "Cancelled by admin".
						const reason = await openModal( {
							title: i18n.cancelTitle || 'Cancel booking',
							body: i18n.confirmCancelWithCal || 'This cancels the booking on Cal.com too — the customer will get a cancellation email and the event will disappear from their calendar. Optional reason below is included in the cancellation email.',
							textarea: true,
							placeholder: i18n.cancelReasonPlaceholder || 'e.g. Schedule conflict — rebooking next week.'
						} );
						if ( null === reason ) { return; }
						await adminFetch( ctx, 'admin/booking/' + bookingId + '/status', { body: { status: 'cancelled', reason: reason || '' } } );
						toast( i18n.saved || 'Saved', 'success' );
						// Sprint 10 fix: patch DOM instead of full reload.
						// Was P1 — owner on the truck waited 2-5s for a
						// reload over cellular for a near-empty action.
						patchBookingStatus( target, 'cancelled' );
					}
					if ( 'mark-completed' === action ) {
						const ok = await openModal( { title: i18n.completeTitle || 'Mark completed', body: i18n.confirmComplete || 'Are you sure?' } );
						if ( ! ok ) { return; }
						await adminFetch( ctx, 'admin/booking/' + bookingId + '/status', { body: { status: 'completed' } } );
						toast( i18n.saved || 'Saved', 'success' );
						patchBookingStatus( target, 'completed' );
					}
					if ( 'clear-override' === action ) {
						await adminFetch( ctx, 'admin/booking/' + bookingId + '/status', { body: { status: '' } } );
						toast( i18n.saved || 'Saved', 'success' );
						window.location.reload();
					}
				} catch ( err ) {
					toast( i18n.saveFailed || 'Save failed', 'error', 3500 );
				}
			} );
		} );
	}

	// ============================================================
	// Person edit + address management (C3)
	// ============================================================

	function initPersonEdit() {
		document.querySelectorAll( '[data-handik-person]' ).forEach( function( ctx ) {
			const contactId = ctx.dataset.contactId;
			ctx.addEventListener( 'click', async function( event ) {
				const target = event.target.closest( '[data-handik-action]' );
				if ( ! target || 'person-save' !== target.dataset.handikAction ) {
					return;
				}
				event.preventDefault();
				const form = ctx.querySelector( '[data-handik-person-edit]' ) || ctx;
				const body = collectFormFields( form, [ 'full_name', 'phone', 'email', 'notes' ] );
				body.is_returning = !! form.querySelector( '[data-field="is_returning"]:checked' );
				body.is_spam      = !! form.querySelector( '[data-field="is_spam"]:checked' );
				try {
					await adminFetch( ctx, 'admin/contact/' + contactId, { method: 'POST', body: body } );
					toast( i18n.saved || 'Saved', 'success' );
				} catch ( err ) {
					toast( i18n.saveFailed || 'Save failed', 'error', 3500 );
				}
			} );
		} );

		// Address actions
		document.querySelectorAll( '[data-handik-addresses]' ).forEach( function( ctx ) {
			ctx.addEventListener( 'click', async function( event ) {
				const target = event.target.closest( '[data-handik-action]' );
				if ( ! target ) {
					return;
				}
				event.preventDefault();
				const action = target.dataset.handikAction;
				const item = target.closest( '[data-address-id]' );
				if ( ! item ) {
					return;
				}
				const addressId = item.dataset.addressId;
				try {
					if ( 'addr-primary' === action ) {
						await adminFetch( ctx, 'admin/address/' + addressId + '/primary', { method: 'POST' } );
						toast( i18n.saved || 'Saved', 'success' );
						window.location.reload();
					}
					if ( 'addr-delete' === action ) {
						// Sprint 10 fix: clearer copy. Server does
						// soft-delete (sets deleted_at) so historical
						// bookings keep referencing the row — modal now
						// says so explicitly. Was P1: owner thought
						// hitting Delete would corrupt past records.
						const ok = await openModal( {
							title: i18n.confirmAddressDeleteTitle || 'Remove this address?',
							body:  i18n.confirmAddressDeleteBody  || 'It will no longer appear in the saved-address dropdown for this customer. Past bookings keep their reference (the row is hidden, not erased).',
							confirmLabel: i18n.remove || 'Remove',
						} );
						if ( ! ok ) { return; }
						await adminFetch( ctx, 'admin/address/' + addressId, { method: 'DELETE' } );
						toast( i18n.saved || 'Saved', 'success' );
						item.remove();
					}
					if ( 'addr-edit' === action ) {
						const result = await openAddressEditModal( {
							label:        item.dataset.label || '',
							address_full: item.dataset.addressFull || '',
							address_unit: item.dataset.addressUnit || '',
							city:         item.dataset.city || '',
							state:        item.dataset.state || '',
							zip_code:     item.dataset.zip || ''
						} );
						if ( null === result ) { return; }
						await adminFetch( ctx, 'admin/address/' + addressId, { method: 'POST', body: result } );
						toast( i18n.saved || 'Saved', 'success' );
						window.setTimeout( function() { window.location.reload(); }, 600 );
					}
				} catch ( err ) {
					toast( i18n.saveFailed || 'Save failed', 'error', 3500 );
				}
			} );
		} );
	}

	function collectFormFields( root, fields ) {
		const out = {};
		fields.forEach( function( name ) {
			const input = root.querySelector( '[data-field="' + name + '"]' );
			if ( input ) {
				out[ name ] = input.value;
			}
		} );
		return out;
	}

	// ============================================================
	// Add person (C3)
	// ============================================================

	function initAddPerson() {
		document.querySelectorAll( '[data-handik-add-person]' ).forEach( function( form ) {
			form.addEventListener( 'submit', async function( event ) {
				event.preventDefault();
				const body = {};
				new FormData( form ).forEach( function( v, k ) { body[ k ] = v; } );
				try {
					const result = await adminFetch( form, 'admin/contact', { method: 'POST', body: body } );
					toast( i18n.saved || 'Saved', 'success' );
					window.setTimeout( function() {
						const url = new URL( window.location.href );
						url.searchParams.set( 'page', 'handik-booking-app-crm' );
						url.searchParams.set( 'contact_id', result.contact_id );
						url.searchParams.delete( 'action' );
						window.location.href = url.toString();
					}, 600 );
				} catch ( err ) {
					toast( err.message || ( i18n.saveFailed || 'Save failed' ), 'error', 4000 );
				}
			} );
		} );
	}

	// ============================================================
	// Catalog editor (D2) — SortableJS + auto-save
	// ============================================================

	function initCatalogEditor() {
		const editors = document.querySelectorAll( '[data-handik-catalog-editor]' );
		if ( ! editors.length ) {
			return;
		}
		editors.forEach( function( editor ) {
			const status = editor.querySelector( '[data-handik-catalog-status]' );
			const groupsRoot = editor.querySelector( '[data-handik-groups]' );

			function setStatus( state, text ) {
				if ( ! status ) { return; }
				status.classList.remove( 'is-saving', 'is-saved', 'is-failed' );
				if ( state ) {
					status.classList.add( 'is-' + state );
				}
				status.textContent = text || '';
			}

			function serialize() {
				return Array.from( groupsRoot.querySelectorAll( '[data-handik-group]' ) ).map( function( g ) {
					return {
						group: ( g.querySelector( '[data-handik-group-name]' ) || {} ).value || '',
						tasks: Array.from( g.querySelectorAll( '[data-handik-task]' ) ).map( function( t ) {
							return {
								id:             ( t.querySelector( '[data-handik-task-id]' ) || {} ).value || '',
								label:          ( t.querySelector( '[data-handik-task-label]' ) || {} ).value || '',
								description:    ( t.querySelector( '[data-handik-task-description]' ) || {} ).value || '',
								rate_label:     ( t.querySelector( '[data-handik-task-rate]' ) || {} ).value || '',
								service_family: ( t.querySelector( '[data-handik-task-service-family]' ) || {} ).value || '',
								rate_family:    ( t.querySelector( '[data-handik-task-rate-family]' ) || {} ).value || ''
							};
						} )
						// Sprint 10 fix: keep partial rows where the user
						// has started typing (id OR label) — was filtering
						// requiring BOTH which silently dropped a row on
						// auto-save when the owner tabbed out mid-edit.
						// Backend filters fully-empty rows; partials stay
						// in source so the next render doesn't lose them.
						.filter( function( t ) {
							return ( t.id && t.label )
								|| ( ( t.id || t.label ) && ( t.description || t.rate_label || t.service_family || t.rate_family ) );
						} )
					};
				} ).filter( function( g ) {
					// Allow group with at least a name (even if all tasks
					// are empty in-progress) — user might rename a category
					// before adding tasks.
					return g.group;
				} );
			}

			let saveTimer = null;
			// Sprint 11 fix: diff-based save. Was P1 — every blur on every
			// field fired a full POST, even with no actual change. The
			// editor has dozens of fields; tab-through-without-typing
			// could fire 30+ identical save requests. Cache the last
			// successfully-saved JSON and only POST when serialize()
			// produces a different shape.
			let lastSavedJson = JSON.stringify( serialize() );

			function scheduleSave() {
				const nextJson = JSON.stringify( serialize() );
				if ( nextJson === lastSavedJson ) {
					// No change — clear any in-flight pending save and
					// quietly drop the status indicator so we don't
					// flash "Saving…" for a no-op.
					if ( saveTimer ) {
						window.clearTimeout( saveTimer );
						saveTimer = null;
					}
					setStatus( '', '' );
					return;
				}
				setStatus( 'saving', i18n.placeholder ? '…' : 'Saving…' );
				if ( saveTimer ) { window.clearTimeout( saveTimer ); }
				saveTimer = window.setTimeout( save, 600 );
			}

			function save() {
				const groups = serialize();
				const snapshotJson = JSON.stringify( groups );
				adminFetch( editor, 'admin/catalog', { body: { groups: groups } } )
					.then( function() {
						lastSavedJson = snapshotJson;
						setStatus( 'saved', i18n.saved || 'Saved' );
						window.setTimeout( function() { setStatus( '', '' ); }, 1800 );
					} )
					.catch( function() {
						setStatus( 'failed', i18n.saveFailed || 'Save failed' );
					} );
			}

			// Add group
			editor.addEventListener( 'click', function( event ) {
				if ( event.target.closest( '[data-handik-add-group]' ) ) {
					event.preventDefault();
					groupsRoot.insertAdjacentHTML( 'beforeend', groupTemplate() );
					attachGroupSortables();
					scheduleSave();
				}
				const addTask = event.target.closest( '[data-handik-add-task]' );
				if ( addTask ) {
					event.preventDefault();
					const tasks = addTask.closest( '[data-handik-group]' ).querySelector( '[data-handik-tasks]' );
					tasks.insertAdjacentHTML( 'beforeend', taskTemplate() );
					scheduleSave();
				}
				const dupTask = event.target.closest( '[data-handik-duplicate-task]' );
				if ( dupTask ) {
					event.preventDefault();
					const task = dupTask.closest( '[data-handik-task]' );
					if ( task ) {
						const clone = task.cloneNode( true );
						const idInput = clone.querySelector( '[data-handik-task-id]' );
						if ( idInput && idInput.value ) {
							idInput.value = idInput.value + '_copy';
						}
						task.parentNode.insertBefore( clone, task.nextSibling );
						scheduleSave();
					}
				}
				const delTask = event.target.closest( '[data-handik-remove-task]' );
				if ( delTask ) {
					event.preventDefault();
					// Sprint 10 fix: ref-aware delete confirm. Was P1 —
					// `window.confirm("Delete?")` gave no signal that 12
					// active requests reference this task. Modal now
					// names the count and uses different copy when the
					// task is in use vs orphan, so click-confirm-gone
					// is no longer accidental.
					const refCount = parseInt( delTask.getAttribute( 'data-handik-ref-count' ) || '0', 10 );
					( async function () {
						let title = i18n.confirmDelete || 'Remove this task?';
						let body  = '';
						if ( refCount > 0 ) {
							title = ( i18n.confirmDeleteInUseTitle || 'Remove this task?' );
							body  = ( i18n.confirmDeleteInUseBody || 'This task is referenced by %d existing request(s). Removing it from the catalog will NOT delete those bookings, but customers will no longer be able to pick it on the public app.' ).replace( '%d', String( refCount ) );
						}
						const ok = await openModal( {
							title: title,
							body: body,
							confirmLabel: refCount > 0 ? ( i18n.removeAnyway || 'Remove anyway' ) : ( i18n.remove || 'Remove' ),
						} );
						if ( ok ) {
							const task = delTask.closest( '[data-handik-task]' );
							if ( task ) { task.remove(); scheduleSave(); }
						}
					} )();
				}
				const delGroup = event.target.closest( '[data-handik-remove-group]' );
				if ( delGroup ) {
					event.preventDefault();
					( async function () {
						const ok = await openModal( {
							title: i18n.confirmDeleteGroupTitle || 'Remove this category?',
							body: i18n.confirmDeleteGroupBody || 'All tasks inside will be removed from the public catalog. Existing bookings keep their data.',
							confirmLabel: i18n.remove || 'Remove',
						} );
						if ( ok ) {
							const g = delGroup.closest( '[data-handik-group]' );
							if ( g ) { g.remove(); scheduleSave(); }
						}
					} )();
				}
			} );

			// Save on blur of any input
			editor.addEventListener( 'change', scheduleSave );
			editor.addEventListener( 'blur', scheduleSave, true );

			// Sprint 7 (a11y): arrow-key reorder for the drag handles. WCAG
			// 2.1.1 — SortableJS is mouse/touch only, so keyboard users
			// previously had no way to change order. Up/Down moves the row
			// among its siblings; Home/End jumps to top/bottom; Enter/Space
			// announces a brief "moved to position X of N" via a polite
			// live region. Save is debounced via the existing scheduleSave.
			let liveRegion = editor.querySelector( '[data-handik-catalog-live]' );
			if ( ! liveRegion ) {
				liveRegion = document.createElement( 'div' );
				liveRegion.setAttribute( 'aria-live', 'polite' );
				liveRegion.setAttribute( 'aria-atomic', 'true' );
				liveRegion.className = 'screen-reader-text';
				liveRegion.dataset.handikCatalogLive = '1';
				editor.appendChild( liveRegion );
			}
			function announce( msg ) {
				liveRegion.textContent = '';
				window.setTimeout( function () { liveRegion.textContent = msg; }, 30 );
			}
			function moveAmongSiblings( handle, delta ) {
				const kind = handle.dataset.handikReorder;
				if ( ! kind ) { return; }
				const item = 'group' === kind ? handle.closest( '[data-handik-group]' ) : handle.closest( '[data-handik-task]' );
				if ( ! item || ! item.parentElement ) { return; }
				const parent = item.parentElement;
				const siblings = Array.prototype.filter.call(
					parent.children,
					function ( el ) { return el === item || el.matches( '[data-handik-group]' ) || el.matches( '[data-handik-task]' ); }
				);
				const currentIndex = siblings.indexOf( item );
				let nextIndex = currentIndex + delta;
				if ( delta === Infinity ) { nextIndex = siblings.length - 1; }
				if ( delta === -Infinity ) { nextIndex = 0; }
				if ( nextIndex < 0 || nextIndex >= siblings.length || nextIndex === currentIndex ) { return; }
				if ( nextIndex < currentIndex ) {
					parent.insertBefore( item, siblings[ nextIndex ] );
				} else {
					const after = siblings[ nextIndex ];
					if ( after.nextSibling ) {
						parent.insertBefore( item, after.nextSibling );
					} else {
						parent.appendChild( item );
					}
				}
				handle.focus();
				announce( ( i18n.reorderedTo || 'Moved to position %1 of %2' )
					.replace( '%1', String( nextIndex + 1 ) )
					.replace( '%2', String( siblings.length ) ) );
				scheduleSave();
			}
			editor.addEventListener( 'keydown', function ( event ) {
				const handle = event.target.closest && event.target.closest( '.handik-catalog-handle' );
				if ( ! handle || ! handle.dataset.handikReorder ) { return; }
				if ( 'ArrowUp' === event.key ) { event.preventDefault(); moveAmongSiblings( handle, -1 ); }
				else if ( 'ArrowDown' === event.key ) { event.preventDefault(); moveAmongSiblings( handle, 1 ); }
				else if ( 'Home' === event.key ) { event.preventDefault(); moveAmongSiblings( handle, -Infinity ); }
				else if ( 'End' === event.key ) { event.preventDefault(); moveAmongSiblings( handle, Infinity ); }
			} );

			function attachGroupSortables() {
				if ( ! window.Sortable ) { return; }
				if ( ! groupsRoot.dataset.sortableInit ) {
					new window.Sortable( groupsRoot, {
						handle: '.handik-catalog-handle',
						animation: 150,
						onEnd: scheduleSave
					} );
					groupsRoot.dataset.sortableInit = '1';
				}
				groupsRoot.querySelectorAll( '[data-handik-tasks]' ).forEach( function( tasksRoot ) {
					if ( tasksRoot.dataset.sortableInit ) { return; }
					new window.Sortable( tasksRoot, {
						handle: '.handik-catalog-handle',
						group: 'handik-tasks',
						animation: 150,
						onEnd: scheduleSave
					} );
					tasksRoot.dataset.sortableInit = '1';
				} );
			}

			// Wait for SortableJS — it's loaded as a separate enqueue.
			if ( window.Sortable ) {
				attachGroupSortables();
			} else {
				const wait = window.setInterval( function() {
					if ( window.Sortable ) {
						window.clearInterval( wait );
						attachGroupSortables();
					}
				}, 200 );
				window.setTimeout( function() { window.clearInterval( wait ); }, 8000 );
			}
		} );
	}

	function groupTemplate() {
		return '<div class="handik-catalog-group" data-handik-group>' +
			'<div class="handik-catalog-group__header">' +
				'<button type="button" class="handik-catalog-handle" data-handik-reorder="group" aria-label="' + ( i18n.reorderCategory || 'Reorder category (arrow keys)' ) + '">⋮⋮</button>' +
				'<label class="handik-admin-field"><span class="handik-admin-field__label">Category title</span><input type="text" data-handik-group-name /></label>' +
				'<button type="button" class="button-link-delete" data-handik-remove-group>Remove</button>' +
			'</div>' +
			'<div class="handik-catalog-group__tasks" data-handik-tasks>' + taskTemplate() + '</div>' +
			'<p><button type="button" class="button button-secondary" data-handik-add-task>+ Add service</button></p>' +
		'</div>';
	}

	function taskTemplate() {
		return '<div class="handik-catalog-task" data-handik-task>' +
			'<button type="button" class="handik-catalog-handle" data-handik-reorder="task" aria-label="' + ( i18n.reorderService || 'Reorder service (arrow keys)' ) + '">⋮⋮</button>' +
			'<div class="handik-catalog-task__fields">' +
				'<div class="handik-admin-grid">' +
					'<label class="handik-admin-field"><span>Service ID</span><input type="text" data-handik-task-id /></label>' +
					'<label class="handik-admin-field"><span>Label</span><input type="text" data-handik-task-label /></label>' +
					'<label class="handik-admin-field"><span>Hourly hint</span><input type="text" data-handik-task-rate /></label>' +
					'<label class="handik-admin-field"><span>Service family</span><input type="text" data-handik-task-service-family /></label>' +
					'<label class="handik-admin-field"><span>Rate family</span><input type="text" data-handik-task-rate-family /></label>' +
				'</div>' +
				'<label class="handik-admin-field handik-admin-field--textarea"><span>Description</span><textarea rows="2" data-handik-task-description></textarea></label>' +
			'</div>' +
			'<div class="handik-catalog-task__actions">' +
				'<button type="button" class="button-link" data-handik-duplicate-task>Duplicate</button>' +
				'<button type="button" class="button-link-delete" data-handik-remove-task>Remove</button>' +
			'</div>' +
		'</div>';
	}

	// ============================================================
	// System tools (D5)
	// ============================================================

	function initSystemTools() {
		document.querySelectorAll( '[data-handik-system-tools]' ).forEach( function( ctx ) {
			ctx.addEventListener( 'click', function( event ) {
				const target = event.target.closest( '[data-handik-action]' );
				if ( ! target ) { return; }
				event.preventDefault();
				const action = target.dataset.handikAction;
				withButtonLoading( target, async function() {
					try {
						if ( 'run-migrations' === action ) {
							const r = await adminFetch( ctx, 'admin/migrations/run', {} );
							// Sprint 10 fix: REST endpoint returns
							// {success, ran[], skipped, error, no_changes}
							// after the Sprint 7 hardening — surface
							// the actual outcome instead of always
							// claiming green. Was P1: a failed step
							// was leaving the toast green and the error
							// invisible until next reload.
							if ( r && r.error ) {
								toast( 'Migration failed: ' + String( r.error ), 'error' );
							} else if ( r && r.ran && r.ran.length ) {
								toast( 'Migrations applied: ' + r.ran.join( ', ' ), 'success' );
							} else if ( r && r.skipped ) {
								toast( 'Skipped — another migration in progress.', 'info' );
							} else {
								toast( 'No pending migrations. DB version: ' + ( r ? r.db_version : '?' ), 'info' );
							}
						}
						if ( 'clear-transients' === action ) {
							await adminFetch( ctx, 'admin/transients/clear', {} );
							toast( 'Transients cleared', 'success' );
						}
					} catch ( err ) {
						toast( ( err && err.message ) || i18n.saveFailed || 'Failed', 'error' );
					}
				} );
			} );
		} );
	}

	// ============================================================
	// Photo lightbox (B2)
	// ============================================================

	function initLightbox() {
		document.querySelectorAll( '[data-handik-lightbox]' ).forEach( function( link ) {
			link.addEventListener( 'click', function( event ) {
				event.preventDefault();
				const url = link.getAttribute( 'href' );
				if ( ! url ) { return; }
				const backdrop = document.createElement( 'div' );
				backdrop.className = 'handik-admin-lightbox-backdrop';
				backdrop.setAttribute( 'role', 'dialog' );
				backdrop.setAttribute( 'aria-modal', 'true' );
				backdrop.tabIndex = -1;
				const img = document.createElement( 'img' );
				img.src = url;
				img.alt = '';
				// Sprint 11 fix: explicit close button + ESC handler.
				// Was P2 — desktop iPad users with a Bluetooth keyboard
				// got stuck (only mouse-click on backdrop dismissed).
				const closeBtn = document.createElement( 'button' );
				closeBtn.type = 'button';
				closeBtn.className = 'handik-admin-lightbox-close';
				closeBtn.setAttribute( 'aria-label', i18n.close || 'Close' );
				closeBtn.textContent = '×';
				const previouslyFocused = document.activeElement;
				function close() {
					document.removeEventListener( 'keydown', onKey, true );
					if ( backdrop.parentNode ) {
						backdrop.parentNode.removeChild( backdrop );
					}
					if ( previouslyFocused && document.contains( previouslyFocused ) && 'function' === typeof previouslyFocused.focus ) {
						try { previouslyFocused.focus(); } catch ( e ) { /* ignore */ }
					}
				}
				function onKey( event ) {
					if ( 'Escape' === event.key ) {
						event.preventDefault();
						close();
					}
				}
				closeBtn.addEventListener( 'click', function ( e ) { e.stopPropagation(); close(); } );
				backdrop.addEventListener( 'click', function ( e ) {
					// Click on the image shouldn't dismiss; only on the
					// backdrop itself (preserves the legacy behaviour).
					if ( e.target === backdrop ) { close(); }
				} );
				backdrop.appendChild( img );
				backdrop.appendChild( closeBtn );
				document.body.appendChild( backdrop );
				document.addEventListener( 'keydown', onKey, true );
				window.requestAnimationFrame( function () { closeBtn.focus(); } );
			} );
		} );
	}

	// ============================================================
	// Row-link, copy, debounced search
	// ============================================================

	function initRowLinks() {
		document.querySelectorAll( '.handik-admin-row-link[data-href]' ).forEach( function( row ) {
			const open = function() {
				const href = row.getAttribute( 'data-href' );
				if ( href ) { window.location.href = href; }
			};
			row.addEventListener( 'click', function( event ) {
				if ( event.target.closest( 'a, button, input, textarea, select, label' ) ) { return; }
				open();
			} );
			row.addEventListener( 'keydown', function( event ) {
				if ( 'Enter' === event.key || ' ' === event.key ) {
					event.preventDefault();
					open();
				}
			} );
		} );
	}

	function initCopyButtons() {
		document.addEventListener( 'click', function( event ) {
			const btn = event.target.closest( '[data-handik-copy]' );
			if ( ! btn ) { return; }
			event.preventDefault();
			const value = btn.dataset.handikCopy;
			if ( ! value ) { return; }
			if ( window.navigator && window.navigator.clipboard ) {
				window.navigator.clipboard.writeText( value ).then( function() {
					toast( i18n.copied || 'Copied', 'success', 1500 );
				} ).catch( function() {} );
			} else {
				const ta = document.createElement( 'textarea' );
				ta.value = value;
				document.body.appendChild( ta );
				ta.select();
				try { document.execCommand( 'copy' ); toast( i18n.copied || 'Copied', 'success', 1500 ); } catch ( e ) {}
				document.body.removeChild( ta );
			}
		} );
	}

	// Sprint 10 fix: surgically update the booking-detail status pill +
	// hide/disable the action button so the page reflects the new state
	// without a full reload (was P1: ~2-5s of cellular flicker for a
	// no-op-feeling action). The pill class set mirrors the PHP mapping
	// in `Handik_Booking_App_Admin_Helpers::status_pill_markup()` —
	// keep these strings in sync when adding new statuses.
	const STATUS_PILL_TONE = {
		booked:    { tone: 'info',    label: 'Booked' },
		confirmed: { tone: 'success', label: 'Confirmed' },
		completed: { tone: 'done',    label: 'Completed' },
		cancelled: { tone: 'danger',  label: 'Cancelled' },
	};
	function patchBookingStatus( triggerEl, newStatus ) {
		const cfg = STATUS_PILL_TONE[ newStatus ];
		if ( ! cfg ) { return; }
		// Sticky bar pill on the detail page.
		document.querySelectorAll( '.handik-admin-pill' ).forEach( function ( pill ) {
			pill.className = 'handik-admin-pill handik-admin-pill--' + cfg.tone;
			pill.textContent = cfg.label;
		} );
		// Disable the action that just fired so a double-tap on a
		// laggy connection doesn't re-POST.
		if ( triggerEl ) {
			triggerEl.disabled = true;
			triggerEl.classList.add( 'is-acted' );
		}
	}

	function initDebouncedSearch() {
		document.querySelectorAll( '[data-handik-debounced-submit]' ).forEach( function( input ) {
			let timer = null;
			// Sprint 10 fix: bumped 350 → 600ms. Was P1 — every keystroke
			// triggered a full page reload on a 4G connection. 600ms is
			// "I stopped typing" without feeling sluggish; full AJAX
			// search is deferred (would need a server-rendered fragment
			// endpoint we don't have yet).
			input.addEventListener( 'input', function() {
				if ( timer ) { window.clearTimeout( timer ); }
				timer = window.setTimeout( function() {
					const form = input.closest( 'form' );
					if ( ! form ) { return; }
					// Sprint 11 fix: stash the current scroll position so
					// the post-reload page lands where the owner was
					// reading instead of jumping to the top after every
					// debounced search. Read-back happens on
					// DOMContentLoaded via initRestoreScroll().
					try {
						window.sessionStorage.setItem(
							'handik_admin_scroll_' + window.location.pathname,
							String( window.pageYOffset || document.documentElement.scrollTop || 0 )
						);
					} catch ( e ) { /* ignore */ }
					form.submit();
				}, 600 );
			} );
		} );
	}

	// ============================================================
	// Boot
	// ============================================================

	// Sprint 10 fix: remember whether collapsible <details> sections are
	// open across page reloads. Each section opts in via
	/**
	 * Sprint 12 — danger-zone delete handler. Listens for clicks on
	 * `[data-handik-delete]` buttons emitted by the booking / request /
	 * person detail pages. Pops the typed-confirm modal, fires the
	 * REST DELETE on success, and on a clean response redirects to the
	 * parent list (or stays put if the redirect attribute is empty).
	 *
	 * Copy + endpoint mapping is data-driven so the markup carries all
	 * the per-entity context — keeps this handler generic.
	 */
	function initDangerZone() {
		const ENTITY = {
			booking: {
				path: 'admin/booking/',
				title: i18n.deleteBookingTitle || 'Permanently delete this booking?',
				// 2.1.27.0 — Cal.com side is now cancelled too as
				// part of the delete flow, so the customer's invite
				// gets the cancel notification and disappears from
				// Apple / Google calendars automatically. Body copy
				// updated to reflect the unified-cancel behavior.
				body:  i18n.deleteBookingBody  || 'The local row will be removed and the booking will be cancelled on Cal.com — the customer will get a cancel-notification email and the event will disappear from their calendar.',
				confirm: i18n.deleteBookingCta || 'Yes, delete this booking',
			},
			'job-request': {
				path: 'admin/job-request/',
				title: i18n.deleteRequestTitle || 'Permanently delete this request?',
				body:  i18n.deleteRequestBody  || 'The request, its transcript, every photo, and any attached bookings will be wiped. The contact stays.',
				confirm: i18n.deleteRequestCta || 'Yes, delete this request',
			},
			contact: {
				path: 'admin/contact/',
				title: i18n.deleteContactTitle || 'Permanently delete this person?',
				body:  i18n.deleteContactBody  || 'Every record that references this person — addresses, requests, transcripts, bookings, photos — will be wiped. Use for spam cleanup or right-to-be-forgotten requests. Irreversible.',
				confirm: i18n.deleteContactCta || 'Yes, delete this person',
			},
		};

		document.querySelectorAll( '[data-handik-delete]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', async function ( event ) {
				event.preventDefault();
				const kind = btn.dataset.handikDelete;
				const id   = parseInt( btn.dataset.handikId || '0', 10 );
				const cfg  = ENTITY[ kind ];
				if ( ! cfg || id <= 0 ) { return; }

				// Pull the preview line from the markup if the page
				// renders one (person + request detail include it
				// inside the section header).
				let preview = '';
				const previewNode = btn.parentElement && btn.parentElement.querySelector( '.handik-admin-muted strong' );
				if ( previewNode ) {
					preview = previewNode.textContent || '';
				}

				let title = cfg.title;
				if ( btn.dataset.handikLabel ) {
					title = title.replace( /\?$/, ' — "' + btn.dataset.handikLabel + '"?' );
				}

				const ok = await openTypedConfirmModal( {
					title:         title,
					body:          cfg.body,
					preview:       preview,
					confirmLabel:  cfg.confirm,
					token:         'DELETE',
				} );
				if ( ! ok ) { return; }

				// 2.1.27.0 — for booking deletes, chain a reason
				// prompt so the Cal.com cancellation notification +
				// customer's calendar invite update carry context.
				// Other entities (job-request, contact) skip this —
				// they don't have a single Cal booking to cancel.
				let deleteReason = '';
				if ( 'booking' === kind ) {
					const r2 = await openModal( {
						title:       i18n.deleteBookingReasonTitle || 'Cancellation reason (optional)',
						body:        i18n.deleteBookingReasonBody  || 'Sent to the customer via the Cal.com cancellation email + included in the calendar invite update. Leave blank for a generic "Cancelled by admin" notice.',
						textarea:    true,
						placeholder: i18n.cancelReasonPlaceholder || 'e.g. Schedule conflict — rebooking next week.'
					} );
					if ( null === r2 ) { return; }
					deleteReason = r2 || '';
				}

				try {
					await withButtonLoading( btn, async function () {
						const fetchOpts = { method: 'DELETE' };
						if ( 'booking' === kind && deleteReason ) {
							fetchOpts.body = { reason: deleteReason };
						}
						const r = await adminFetch( null, cfg.path + id, fetchOpts );
						const sumLines = [];
						if ( r && r.summary ) {
							for ( const k in r.summary ) {
								if ( r.summary[ k ] > 0 ) {
									sumLines.push( r.summary[ k ] + ' ' + k.replace( /_/g, ' ' ) );
								}
							}
						}
						toast(
							( i18n.deletedSummary || 'Deleted: %s' ).replace( '%s', sumLines.join( ', ' ) || cfg.confirm ),
							'success'
						);
					} );
				} catch ( err ) {
					toast( ( err && err.message ) || i18n.saveFailed || 'Delete failed', 'error' );
					return;
				}

				if ( btn.dataset.handikRedirect ) {
					window.location.href = btn.dataset.handikRedirect;
				} else {
					window.location.reload();
				}
			} );
		} );
	}

	// `data-handik-details-key="<unique key>"`. State is per-tab via
	// sessionStorage so opening a panel in one tab doesn't haunt another.
	function initDetailsMemory() {
		const STORAGE_PREFIX = 'handik_details_open_';
		document.querySelectorAll( 'details[data-handik-details-key]' ).forEach( function ( el ) {
			const key = STORAGE_PREFIX + el.dataset.handikDetailsKey;
			try {
				// Sprint 11 fix: respect both '1' (open) AND '0' (closed)
				// from storage. The Sprint 10 version only honored '1'
				// — once an owner closed a section, the next page load
				// reverted to the markup default (almost always `open`).
				// Now closed state persists across reloads too.
				const stored = window.sessionStorage.getItem( key );
				if ( '1' === stored ) { el.open = true; }
				else if ( '0' === stored ) { el.open = false; }
			} catch ( e ) { /* private mode, ignore */ }
			el.addEventListener( 'toggle', function () {
				try {
					window.sessionStorage.setItem( key, el.open ? '1' : '0' );
				} catch ( e ) { /* ignore */ }
			} );
		} );
	}

	// Sprint 11 fix: paired with the debounced-search scroll save.
	// Restores the saved scroll position once on load if the previous
	// page on the same path stashed one. Cleared after restore so a
	// later browse-back doesn't haunt unrelated navigation.
	function initRestoreScroll() {
		try {
			const key = 'handik_admin_scroll_' + window.location.pathname;
			const saved = window.sessionStorage.getItem( key );
			if ( saved ) {
				window.sessionStorage.removeItem( key );
				const y = parseInt( saved, 10 );
				if ( y > 0 ) {
					window.requestAnimationFrame( function () {
						window.scrollTo( { top: y, behavior: 'auto' } );
					} );
				}
			}
		} catch ( e ) { /* private mode, ignore */ }
	}

	/**
	 * Sprint 13 — admin-initiated direct booking page handler.
	 *
	 * Wires up the [data-handik-new-booking] container that
	 * Admin_Bookings::render_new_booking emits:
	 *
	 *   - Customer mode toggle (existing vs new walk-in).
	 *   - Existing-customer search (debounced REST call to
	 *     /admin/contact/search; result list populates the address
	 *     dropdown so the operator never has to retype).
	 *   - New-address sub-form revealed when the address picker
	 *     selects "+ New address".
	 *   - Submit: POST /admin/booking/new → on success, swaps the Cal
	 *     embed into [data-handik-cal-frame] using the same
	 *     parseCalEmbedConfig pattern booking-forms.js uses.
	 *   - Cal embed `bookingSuccessful` → POST /forms/direct/{id}/capture
	 *     with the issued capture_token to flip the row to BOOKED.
	 *     Redirect to Bookings list afterwards (the cal-webhook will
	 *     also keep doing its dispatch — we don't need to wait for it).
	 */
	function initNewBookingFlow() {
		const root = document.querySelector( '[data-handik-new-booking]' );
		if ( ! root ) { return; }

		const restBase  = root.dataset.handikRestBase || config.restBase;
		const restNonce = root.dataset.handikRestNonce || config.restNonce;
		const redirectBase = root.dataset.handikRedirectBase || '';

		const modeBtns       = root.querySelectorAll( '[data-handik-mode]' );
		const paneExisting   = root.querySelector( '[data-handik-pane="existing"]' );
		const paneNew        = root.querySelector( '[data-handik-pane="new"]' );
		const searchInput    = root.querySelector( '[data-handik-contact-search]' );
		const resultsList    = root.querySelector( '[data-handik-results]' );
		const chosenBox      = root.querySelector( '[data-handik-chosen]' );
		const chosenIdInput  = root.querySelector( '[data-handik-contact-id]' );
		const chosenName     = root.querySelector( '[data-handik-chosen-name]' );
		const chosenPhone    = root.querySelector( '[data-handik-chosen-phone]' );
		const addressPicker  = root.querySelector( '[data-handik-address-picker]' );
		const newAddressPane = root.querySelector( '[data-handik-new-address]' );
		const presetPicker   = root.querySelector( '[data-handik-preset-picker]' );
		const submitBtn      = root.querySelector( '[data-handik-new-booking-submit]' );
		const statusEl       = root.querySelector( '[data-handik-new-booking-status]' );
		const calSection     = document.querySelector( '[data-handik-cal-section]' );
		const calFrameSlot   = document.querySelector( '[data-handik-cal-frame]' );

		let mode = paneExisting && ! paneExisting.hidden ? 'existing' : 'existing';

		function setMode( next ) {
			mode = next;
			modeBtns.forEach( function ( b ) {
				b.setAttribute( 'aria-pressed', b.dataset.handikMode === next ? 'true' : 'false' );
			} );
			if ( paneExisting ) { paneExisting.hidden = ( next !== 'existing' ); }
			if ( paneNew )      { paneNew.hidden      = ( next !== 'new' );      }
			// Sprint 13 hotfix (F9): clear the inactive pane so a
			// half-filled walk-in form doesn't get submitted with an
			// existing-customer pick (or vice versa). Switching modes
			// is a deliberate "throw the previous attempt away" gesture.
			if ( 'existing' === next ) {
				root.querySelectorAll( '[data-handik-pane="new"] input' ).forEach( function ( i ) { i.value = ''; } );
			} else {
				if ( chosenIdInput ) { chosenIdInput.value = ''; }
				if ( searchInput )   { searchInput.value = ''; }
				if ( chosenName )    { chosenName.textContent = ''; }
				if ( chosenPhone )   { chosenPhone.textContent = ''; }
				if ( chosenBox )     { chosenBox.hidden = true; }
				if ( resultsList )   { resultsList.hidden = true; resultsList.innerHTML = ''; }
				if ( newAddressPane ){ newAddressPane.hidden = true; }
			}
			updateSubmitState();
		}
		modeBtns.forEach( function ( b ) {
			b.addEventListener( 'click', function () { setMode( b.dataset.handikMode ); } );
		} );

		// ----- Existing-customer search -----
		let searchTimer = null;
		if ( searchInput ) {
			searchInput.addEventListener( 'input', function () {
				if ( searchTimer ) { window.clearTimeout( searchTimer ); }
				const q = searchInput.value.trim();
				if ( q.length < 2 ) {
					if ( resultsList ) { resultsList.hidden = true; resultsList.innerHTML = ''; }
					return;
				}
				searchTimer = window.setTimeout( function () {
					window.fetch( restBase + 'admin/contact/search?q=' + encodeURIComponent( q ), {
						credentials: 'same-origin',
						headers: { 'X-WP-Nonce': restNonce },
					} )
						.then( function ( r ) { return r.json(); } )
						.then( function ( payload ) { renderResults( payload && payload.results ? payload.results : [] ); } )
						.catch( function () { /* ignore */ } );
				}, 300 );
			} );
		}

		function renderResults( results ) {
			if ( ! resultsList ) { return; }
			resultsList.innerHTML = '';
			if ( ! results.length ) {
				resultsList.hidden = true;
				return;
			}
			results.forEach( function ( r ) {
				const li = document.createElement( 'li' );
				li.className = 'handik-admin-new-booking__result';
				li.tabIndex = 0;
				li.innerHTML = '<strong>' + escapeHtml( r.full_name ) + '</strong>'
					+ ' <span class="handik-admin-muted">· ' + escapeHtml( r.phone ) + '</span>'
					+ ( r.email ? ' <span class="handik-admin-muted">· ' + escapeHtml( r.email ) + '</span>' : '' );
				li.addEventListener( 'click', function () { chooseContact( r ); } );
				li.addEventListener( 'keydown', function ( e ) {
					if ( 'Enter' === e.key || ' ' === e.key ) { e.preventDefault(); chooseContact( r ); }
				} );
				resultsList.appendChild( li );
			} );
			resultsList.hidden = false;
		}

		function chooseContact( r ) {
			if ( chosenIdInput ) { chosenIdInput.value = String( r.id ); }
			if ( chosenName )    { chosenName.textContent = r.full_name; }
			if ( chosenPhone )   { chosenPhone.textContent = '· ' + r.phone; }
			if ( resultsList )   { resultsList.hidden = true; resultsList.innerHTML = ''; }
			if ( searchInput )   { searchInput.value = r.full_name; }
			if ( addressPicker ) {
				addressPicker.innerHTML = '';
				const placeholder = document.createElement( 'option' );
				placeholder.value = '';
				placeholder.textContent = i18n.pickAddress || '— Pick an address —';
				addressPicker.appendChild( placeholder );
				( r.addresses || [] ).forEach( function ( a ) {
					const opt = document.createElement( 'option' );
					opt.value = String( a.id );
					opt.dataset.full = a.address_full || '';
					opt.dataset.unit = a.address_unit || '';
					opt.textContent = ( a.address_full || '' ) + ( a.address_unit ? ' · ' + a.address_unit : '' );
					addressPicker.appendChild( opt );
				} );
				const newOpt = document.createElement( 'option' );
				newOpt.value = '__new';
				newOpt.textContent = i18n.newAddress || '+ New address';
				addressPicker.appendChild( newOpt );
			}
			// Sprint 13 hotfix (F8): clear stale "+ New address" inputs +
			// re-hide the sub-pane when switching contacts. Otherwise:
			// pick A → "+ New address" → type "123 Main" → pick B → the
			// stale "123 Main" stays visible (and would be sent if the
			// operator forgets and submits while the picker is on
			// `__new`).
			if ( newAddressPane ) { newAddressPane.hidden = true; }
			const newAddrFull = root.querySelector( '[data-handik-address-full]' );
			const newAddrUnit = root.querySelector( '[data-handik-address-unit]' );
			if ( newAddrFull ) { newAddrFull.value = ''; }
			if ( newAddrUnit ) { newAddrUnit.value = ''; }
			if ( chosenBox ) { chosenBox.hidden = false; }
			updateSubmitState();
		}

		// ----- New-address toggle on the address picker -----
		if ( addressPicker ) {
			addressPicker.addEventListener( 'change', function () {
				if ( newAddressPane ) {
					newAddressPane.hidden = ( '__new' !== addressPicker.value );
				}
				updateSubmitState();
			} );
		}

		// ----- Submit-button gating -----
		root.addEventListener( 'input', updateSubmitState );

		function updateSubmitState() {
			if ( ! submitBtn ) { return; }
			const presetOk = presetPicker && presetPicker.value;
			let payloadOk = false;
			if ( 'existing' === mode ) {
				const cid = chosenIdInput && chosenIdInput.value ? parseInt( chosenIdInput.value, 10 ) : 0;
				if ( cid > 0 && addressPicker ) {
					const addr = addressPicker.value;
					if ( '__new' === addr ) {
						const af = root.querySelector( '[data-handik-address-full]' );
						payloadOk = !! ( af && af.value.trim() );
					} else if ( addr ) {
						payloadOk = true;
					}
				}
			} else {
				const n  = root.querySelector( '[data-handik-new-name]' );
				const p  = root.querySelector( '[data-handik-new-phone]' );
				const af = root.querySelector( '[data-handik-new-address-full]' );
				payloadOk = !! ( n && n.value.trim() && p && p.value.trim() && af && af.value.trim() );
			}
			submitBtn.disabled = ! ( presetOk && payloadOk );
		}

		// ----- Submit -----
		if ( submitBtn ) {
			let draftCreated = false;
			submitBtn.addEventListener( 'click', async function () {
				// Sprint 13 hotfix (F4): once a draft + Cal embed mount
				// successfully, freeze the submit so a stray click doesn't
				// POST a second admin/booking/new and create a duplicate
				// `direct_booking_requests` row. Re-enable only on hard
				// reload of the page.
				if ( draftCreated ) { return; }
				const body = collectPayload();
				if ( ! body ) { return; }
				if ( statusEl ) { statusEl.textContent = i18n.creatingDraft || '· Creating draft…'; }
				try {
					await withButtonLoading( submitBtn, async function () {
						const r = await window.fetch( restBase + 'admin/booking/new', {
							method: 'POST',
							credentials: 'same-origin',
							headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': restNonce },
							body: JSON.stringify( body ),
						} );
						const payload = await r.json().catch( function () { return {}; } );
						if ( ! r.ok ) {
							throw new Error( payload.message || payload.code || 'Failed' );
						}
						if ( ! payload.cal_booking_url ) {
							throw new Error( i18n.noCalUrl || 'Cal.com URL missing — check the preset configuration.' );
						}
						mountCalEmbed( payload );
						draftCreated = true;
						submitBtn.disabled = true;
						submitBtn.textContent = i18n.draftCreated || 'Draft created · pick a slot below';
						// Visually freeze the form so the operator
						// doesn't keep editing fields that no longer
						// affect the embed.
						root.classList.add( 'is-frozen' );
					} );
				} catch ( err ) {
					if ( statusEl ) {
						statusEl.textContent = '';
					}
					toast( ( err && err.message ) || i18n.saveFailed || 'Failed', 'error' );
				}
			} );
		}

		function collectPayload() {
			const body = {
				preset_slug: presetPicker ? presetPicker.value : '',
			};
			if ( 'existing' === mode ) {
				body.contact_id = chosenIdInput && chosenIdInput.value ? parseInt( chosenIdInput.value, 10 ) : 0;
				const addr = addressPicker ? addressPicker.value : '';
				if ( '__new' === addr ) {
					const af = root.querySelector( '[data-handik-address-full]' );
					const au = root.querySelector( '[data-handik-address-unit]' );
					body.address_full = af ? af.value.trim() : '';
					body.address_unit = au ? au.value.trim() : '';
				} else if ( addr ) {
					body.address_id = parseInt( addr, 10 );
				}
			} else {
				const n  = root.querySelector( '[data-handik-new-name]' );
				const p  = root.querySelector( '[data-handik-new-phone]' );
				const e  = root.querySelector( '[data-handik-new-email]' );
				const af = root.querySelector( '[data-handik-new-address-full]' );
				const au = root.querySelector( '[data-handik-new-address-unit]' );
				body.full_name    = n  ? n.value.trim()  : '';
				body.phone        = p  ? p.value.trim()  : '';
				body.email        = e  ? e.value.trim()  : '';
				body.address_full = af ? af.value.trim() : '';
				body.address_unit = au ? au.value.trim() : '';
			}
			return body;
		}

		// ----- Cal embed mount -----
		// Deliberately mirrors booking-forms.js mountCalEmbed so the
		// embed behaves identically. Captures the bookingSuccessful
		// event to flip the row to BOOKED via the existing public
		// /forms/direct/{id}/capture endpoint.
		function mountCalEmbed( submission ) {
			if ( ! calSection || ! calFrameSlot ) { return; }
			const parsed = parseCalEmbedUrl( submission.cal_booking_url );
			if ( ! parsed ) {
				toast( i18n.noCalUrl || 'Could not parse Cal.com URL.', 'error' );
				return;
			}
			calSection.hidden = false;
			calSection.scrollIntoView( { behavior: 'smooth', block: 'start' } );

			loadCalScript( parsed.origin )
				.then( function () {
					const namespace = 'handik_admin_new_booking';
					window.__handikCalNamespaces = window.__handikCalNamespaces || {};
					if ( ! window.__handikCalNamespaces[ namespace ] ) {
						window.Cal( 'init', namespace, { origin: parsed.origin } );
						window.__handikCalNamespaces[ namespace ] = true;
					}
					const ns = ( window.Cal.ns && window.Cal.ns[ namespace ] ) ? window.Cal.ns[ namespace ] : window.Cal;
					ns( 'inline', {
						elementOrSelector: calFrameSlot,
						calLink: parsed.calLink,
						config:  parsed.config,
					} );
					ns( 'on', {
						action: 'bookingSuccessful',
						callback: function ( e ) {
							captureBooking( submission, e && e.detail && e.detail.data ? e.detail.data : {} );
						},
					} );
					if ( statusEl ) { statusEl.textContent = i18n.calLoaded || '· Cal.com calendar loaded — pick a slot below.'; }
				} )
				.catch( function () {
					calFrameSlot.innerHTML = '<a class="button button-primary" target="_blank" rel="noopener" href="'
						+ String( submission.cal_booking_url ).replace( /"/g, '&quot;' ) + '">'
						+ ( i18n.openInNewTab || 'Open Cal.com in a new tab' ) + '</a>';
				} );
		}

		// Sprint 13 hotfix (F6): client-side guard against Cal.com firing
		// `bookingSuccessful` twice for the same booking. Server is
		// idempotent (capture_booking short-circuits on STATUS_BOOKED),
		// but a duplicate fetch still races the toast + redirect. Mirror
		// the public form's `state._captureSent` flag.
		let captureSent = false;

		function captureBooking( submission, detail ) {
			if ( captureSent ) { return; }
			captureSent = true;
			// Sprint 13 hotfix (F1+F2): the server reads `booking_payload`,
			// not `booking`. The previous shape made capture_booking()
			// receive the entire envelope (token + booking) as the payload,
			// so cal_booking_id / cal_booking_uid were saved as empty
			// strings — local row had no Cal handle until the webhook
			// arrived. Now matches `booking-forms.js`'s shape exactly.
			window.fetch( restBase + 'forms/direct/' + submission.request_id + '/capture', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': restNonce },
				body: JSON.stringify( {
					capture_token:  submission.capture_token,
					booking_payload: detail || {},
				} ),
			} )
				.then( function () {
					toast( i18n.bookingRecorded || 'Booking recorded.', 'success' );
					if ( redirectBase ) {
						window.setTimeout( function () { window.location.href = redirectBase; }, 1200 );
					}
				} )
				.catch( function () {
					toast( i18n.bookingRecordedWebhook || 'Booking recorded; the webhook will reconcile it shortly.', 'info' );
				} );
		}

		// ----- Cal embed helpers (mirror booking-forms.js parseCalEmbedConfig) -----
		// Sprint 13 hotfix (F5): keep parity with `booking-forms.js`.
		// Drop Cal-side deep-link params that would force the embed
		// into a specific date/slot (which then renders "no slots
		// available"), and re-prefix the phone with `+` if it lost
		// the literal during URL encoding.
		const CAL_EMBED_DROP_PARAMS = {
			overlayCalendar: true,
			month:           true,
			date:            true,
			slot:            true,
			embed:           true,
			embed_origin:    true,
			layout:          true,
		};

		function parseCalEmbedUrl( raw ) {
			try {
				const url = new URL( raw );
				const pathParts = url.pathname.split( '/' ).filter( Boolean );
				if ( ! pathParts.length ) { return null; }
				const calLink = pathParts.join( '/' );
				const config = {};
				url.searchParams.forEach( function ( v, k ) {
					if ( CAL_EMBED_DROP_PARAMS[ k ] ) { return; }
					if ( 'phone' === k ) {
						const stripped = String( v || '' ).replace( /[\s()-]+/g, '' );
						config[ k ] = stripped && '+' !== stripped.charAt( 0 ) ? '+' + stripped : stripped;
						return;
					}
					config[ k ] = v;
				} );
				return { origin: url.origin || 'https://cal.com', calLink: calLink, config: config };
			} catch ( e ) {
				return null;
			}
		}
		function loadCalScript( origin ) {
			return new Promise( function ( resolve, reject ) {
				if ( window.Cal && 'function' === typeof window.Cal ) { resolve(); return; }
				const existing = document.getElementById( 'handik-admin-cal-script' );
				if ( existing ) {
					existing.addEventListener( 'load', resolve, { once: true } );
					existing.addEventListener( 'error', reject, { once: true } );
					return;
				}
				// Same Cal queue stub as the public form.
				( function ( C, A, L ) {
					const p = function ( a, ar ) { a.q.push( ar ); };
					const d = C.document;
					C.Cal = C.Cal || function () {
						const cal = C.Cal; const ar = arguments;
						if ( ! cal.loaded ) { cal.ns = {}; cal.q = cal.q || []; d.head.appendChild( d.createElement( 'script' ) ).src = A; cal.loaded = true; }
						if ( ar[ 0 ] === L ) {
							const api = function () { p( api, arguments ); };
							const namespace = ar[ 1 ];
							api.q = api.q || [];
							if ( typeof namespace === 'string' ) { cal.ns[ namespace ] = cal.ns[ namespace ] || api; p( cal.ns[ namespace ], ar ); p( cal, [ 'initNamespace', namespace ] ); } else { p( cal, ar ); }
							return;
						}
						p( cal, ar );
					};
				} )( window, origin + '/embed/embed.js', 'init' );
				const tag = document.querySelector( 'script[src="' + origin + '/embed/embed.js"]' );
				if ( tag ) {
					tag.id = 'handik-admin-cal-script';
					tag.addEventListener( 'load', resolve, { once: true } );
					tag.addEventListener( 'error', reject, { once: true } );
				} else {
					resolve();
				}
			} );
		}
	}

	function escapeHtml( s ) {
		return String( s == null ? '' : s ).replace( /[&"<>]/g, function ( c ) {
			return ( { '&': '&amp;', '"': '&quot;', '<': '&lt;', '>': '&gt;' } )[ c ];
		} );
	}

	// ============================================================
	// 2.1.23.1 — A5: "Load chat from OpenAI" button on the admin
	// booking detail "What the customer wrote" panel. Calls
	// /admin/booking/{id}/fetch-chat, which pages through the
	// authoritative OpenAI ChatKit thread and backfills
	// handik_messages. Reloads on success so the transcript renders.
	// ============================================================

	function initFetchChat() {
		document.querySelectorAll( '[data-handik-fetch-chat]' ).forEach( function( btn ) {
			btn.addEventListener( 'click', async function( event ) {
				event.preventDefault();
				const bookingId = btn.dataset.bookingId;
				if ( ! bookingId ) { return; }
				const status = btn.parentElement
					? btn.parentElement.querySelector( '[data-handik-fetch-chat-status]' )
					: null;
				if ( status ) {
					status.textContent = i18n.fetchingChat || 'Fetching chat history…';
				}
				try {
					const result = await withButtonLoading( btn, function() {
						return adminFetch( btn, 'admin/booking/' + bookingId + '/fetch-chat', { body: {} } );
					} );
					const fetched  = result && typeof result.fetched  === 'number' ? result.fetched  : 0;
					const inserted = result && typeof result.inserted === 'number' ? result.inserted : 0;
					if ( fetched > 0 ) {
						toast(
							( i18n.fetchedChat || 'Fetched %1$d messages (%2$d new).' )
								.replace( '%1$d', String( fetched ) )
								.replace( '%2$d', String( inserted ) ),
							'success'
						);
						window.setTimeout( function() {
							window.location.reload();
						}, 600 );
					} else {
						if ( status ) {
							status.textContent = i18n.noChatFound || 'OpenAI returned no messages for this thread.';
						} else {
							toast( i18n.noChatFound || 'OpenAI returned no messages for this thread.', 'info', 3500 );
						}
					}
				} catch ( err ) {
					const msg = ( err && err.message ) ? err.message : ( i18n.fetchFailed || 'Fetch failed' );
					if ( status ) {
						status.textContent = msg;
					}
					toast( msg, 'error', 4500 );
				}
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function() {
		initRowLinks();
		initBookingActions();
		initPersonEdit();
		initAddPerson();
		initCatalogEditor();
		initSystemTools();
		initLightbox();
		initCopyButtons();
		initDebouncedSearch();
		initDetailsMemory();
		initRestoreScroll();
		initDangerZone();
		initNewBookingFlow();
		initFetchChat();
		initBulkDeleteDrafts();
		initPullFromCal();
		initBulkMode();
	} );

	// ============================================================
	// 2.1.26.3: generic bulk-mode toggler + selection apply for
	// page-level lists. Used by Bookings and People & Requests
	// (and any future list that opts in via `data-handik-bulk-
	// section` on a container + `data-handik-bulk-toggle` on a
	// trigger button + per-row `data-handik-bulk-row value=...`
	// checkboxes inside the container).
	//
	// Activation:
	//   - Trigger button carries `data-handik-bulk-target=".css-selector"`
	//     pointing at the container element. Clicking it toggles the
	//     `is-bulk-mode` class on that container + flips its
	//     `[data-handik-bulk-bar]` strip visibility.
	//   - The container's `data-bulk-endpoint` is the REST URL
	//     to POST `ids: [...]` to. `data-rest-nonce` carries the
	//     WP REST nonce. `data-bulk-kind` is used in the toast
	//     ("Deleted 5 bookings" vs "Deleted 5 contacts" etc).
	// ============================================================
	function initBulkMode() {
		const containers = Array.from( document.querySelectorAll( '[data-handik-bulk-section]' ) );
		containers.forEach( function( container ) {
			const endpoint = container.dataset.bulkEndpoint || '';
			const nonce    = container.dataset.restNonce    || '';
			const kind     = container.dataset.bulkKind     || 'rows';
			const bar      = container.querySelector( '[data-handik-bulk-bar]' );
			const countEl  = container.querySelector( '[data-handik-bulk-count]' );
			const toggleAll = container.querySelector( '[data-handik-bulk-toggle-all]' );
			const applyBtn = container.querySelector( '[data-handik-bulk-apply]' );

			function getCheckboxes() {
				return Array.from( container.querySelectorAll( '[data-handik-bulk-row]' ) );
			}

			// 2.1.26.4 critical-data-loss fix: cards-view and table-
			// view both render their own copy of each row's checkbox
			// with the same `value=<id>`. Toggle-all checks both
			// copies, but a manual uncheck only flips the one the
			// user clicked. When the user "selected all and un-
			// checked 15 to keep", the cards-view copies of those 15
			// went unchecked but the table-view copies stayed
			// checked. JS's :checked collection still included them,
			// the apply payload had every id, and the server deleted
			// everything. Sync all duplicates whenever any single
			// checkbox changes — the visible state in cards/table
			// stays consistent regardless of which view the user
			// interacted with.
			function syncDuplicates( target ) {
				if ( ! target || ! target.value ) { return; }
				const newState = target.checked;
				const valNum   = parseInt( target.value, 10 );
				if ( ! ( valNum > 0 ) ) { return; }
				// Build the selector with the integer value — no need to
				// CSS-escape since booking/contact ids are always positive
				// integers parsed above.
				container.querySelectorAll( '[data-handik-bulk-row][value="' + valNum + '"]' ).forEach( function( c ) {
					if ( c !== target ) { c.checked = newState; }
				} );
			}

			function getUniqueCheckedIds() {
				const boxes = getCheckboxes();
				const ids   = new Set();
				boxes.forEach( function( c ) {
					if ( c.checked ) {
						const v = parseInt( c.value, 10 );
						if ( v > 0 ) { ids.add( v ); }
					}
				} );
				return Array.from( ids );
			}

			function getUniqueIds() {
				const boxes = getCheckboxes();
				const ids   = new Set();
				boxes.forEach( function( c ) {
					const v = parseInt( c.value, 10 );
					if ( v > 0 ) { ids.add( v ); }
				} );
				return ids.size;
			}

			function refresh() {
				const checkedIds = getUniqueCheckedIds();
				const totalIds   = getUniqueIds();
				const n = checkedIds.length;
				if ( countEl ) {
					countEl.textContent = String( n ) + ' ' + ( i18n.selected || 'selected' );
				}
				if ( applyBtn ) {
					applyBtn.disabled = 0 === n;
				}
				if ( toggleAll ) {
					toggleAll.checked = n > 0 && n === totalIds;
					toggleAll.indeterminate = n > 0 && n < totalIds;
				}
			}

			function enterBulkMode() {
				container.classList.add( 'is-bulk-mode' );
				if ( bar ) { bar.hidden = false; }
				refresh();
			}

			function exitBulkMode() {
				container.classList.remove( 'is-bulk-mode' );
				if ( bar ) { bar.hidden = true; }
				getCheckboxes().forEach( function( c ) { c.checked = false; } );
				refresh();
			}

			// Hook the toggle button(s) that point at this container.
			const selector = '[data-handik-bulk-toggle]';
			document.querySelectorAll( selector ).forEach( function( btn ) {
				const targetSel = btn.dataset.handikBulkTarget;
				if ( ! targetSel ) { return; }
				if ( ! container.matches( targetSel ) ) { return; }
				btn.addEventListener( 'click', function( event ) {
					event.preventDefault();
					if ( container.classList.contains( 'is-bulk-mode' ) ) {
						btn.textContent = i18n.bulkSelect || 'Select';
						exitBulkMode();
					} else {
						btn.textContent = i18n.bulkDone || 'Done';
						enterBulkMode();
					}
				} );
			} );

			if ( toggleAll ) {
				toggleAll.addEventListener( 'change', function() {
					getCheckboxes().forEach( function( c ) { c.checked = toggleAll.checked; } );
					refresh();
				} );
			}

			container.addEventListener( 'change', function( event ) {
				const target = event.target;
				if ( target && target.matches && target.matches( '[data-handik-bulk-row]' ) ) {
					syncDuplicates( target );
					refresh();
				}
			} );

			// Prevent the checkbox click from also navigating the
			// underlying card/row link. The checkbox is a sibling
			// of the link on cards (separate DOM nodes) but inside
			// a clickable <tr> on tables — stop propagation there.
			container.addEventListener( 'click', function( event ) {
				const target = event.target;
				if ( target && target.matches && target.matches( '[data-handik-bulk-row]' ) ) {
					event.stopPropagation();
				}
			} );

			if ( applyBtn ) {
				applyBtn.addEventListener( 'click', async function( event ) {
					event.preventDefault();
					const ids = getUniqueCheckedIds();
					if ( 0 === ids.length ) { return; }
					// 2.1.26.4 critical-data-loss safety: type-to-confirm
					// modal — owner must type "DELETE <count>" literally
					// before the destructive POST goes out. Same pattern
					// the danger-zone single-row delete uses. Prevents the
					// "I clicked Select All by mistake → wiped everything"
					// scenario the owner reported.
					const totalIds   = getUniqueIds();
					const sel        = String( ids.length );
					const tot        = String( totalIds );
					const requireToken = 'DELETE ' + sel;
					// 2.1.27.0 — booking bulk-delete now cancels each
					// booking on Cal.com first so the customer's invite
					// disappears from their calendar. Contact bulk-
					// delete cascades through cascade_delete which
					// also drops each contact's bookings, so the same
					// per-booking Cal cancel logic applies on the
					// server-side. Update the prompt copy to set
					// expectations.
					const promptTpl = 'contacts' === kind
						? ( i18n.bulkDeleteContactsPrompt || 'About to delete %1$s of %2$s contacts AND all their requests, bookings, addresses, photos and messages. Bookings will also be cancelled on Cal.com. This is irreversible. Type %3$s to confirm.' )
						: ( i18n.bulkDeleteBookingsPrompt || 'About to delete %1$s of %2$s bookings. Each will be cancelled on Cal.com — customers will get cancellation emails and the events will disappear from their calendars. This is irreversible. Type %3$s to confirm.' );
					const body = promptTpl
						.replace( '%1$s', sel )
						.replace( '%2$s', tot )
						.replace( '%3$s', requireToken );
					const typed = await openModal( {
						title: i18n.bulkDeleteTitle || 'Delete',
						body: body,
						input: true,
						placeholder: requireToken,
					} );
					if ( null === typed || typed.trim() !== requireToken ) {
						if ( typed !== null ) {
							toast( i18n.bulkDeleteMismatch || 'Confirmation text did not match. Nothing deleted.', 'warning', 3500 );
						}
						return;
					}
					// 2.1.27.0 — second modal: optional reason text
					// (applied to every Cal cancellation in this bulk).
					// Skip for contact-bulk since the typed-confirm
					// modal copy already hints at the cascade scope.
					let bulkReason = '';
					if ( 'bookings' === kind ) {
						const r3 = await openModal( {
							title:       i18n.bulkCancelReasonTitle || 'Cancellation reason (optional)',
							body:        i18n.bulkCancelReasonBody  || 'Applied to every booking in this batch. Sent to customers via the Cal.com cancellation email. Leave blank for a generic "Cancelled by admin" notice.',
							textarea:    true,
							placeholder: i18n.cancelReasonPlaceholder || 'e.g. Schedule conflict — rebooking next week.'
						} );
						if ( null === r3 ) { return; }
						bulkReason = r3 || '';
					}
					try {
						const result = await withButtonLoading( applyBtn, function() {
							return window.fetch( endpoint, {
								method: 'POST',
								credentials: 'same-origin',
								headers: {
									'X-WP-Nonce': nonce,
									'Content-Type': 'application/json',
								},
								body: JSON.stringify( { ids: ids, reason: bulkReason } ),
							} ).then( function( response ) {
								return response.json().catch( function() { return {}; } ).then( function( payload ) {
									if ( ! response.ok ) {
										const error = new Error( payload.message || payload.code || 'Request failed' );
										error.status = response.status;
										throw error;
									}
									return payload;
								} );
							} );
						} );
						const deleted = result && typeof result.deleted === 'number' ? result.deleted : 0;
						const tpl = 'contacts' === kind
							? ( i18n.bulkDeleteContactsDone || 'Deleted %d contacts.' )
							: ( i18n.bulkDeleteBookingsDone || 'Deleted %d bookings.' );
						toast( tpl.replace( '%d', String( deleted ) ), 'success' );
						window.setTimeout( function() {
							window.location.reload();
						}, 600 );
					} catch ( err ) {
						const msg = ( err && err.message ) ? err.message : ( i18n.saveFailed || 'Delete failed' );
						toast( msg, 'error', 4500 );
					}
				} );
			}

			refresh();
		} );
	}

	// ============================================================
	// 2.1.26.2: "Pull from Cal.com" backfill button on the Bookings
	// list page. Lists Cal bookings via /admin/bookings/pull-from-cal
	// and upserts any that aren't already mirrored. Toasts a summary
	// (fetched / already-present / inserted) and reloads when at
	// least one row was inserted so the new bookings appear in the
	// list.
	// ============================================================
	function initPullFromCal() {
		document.querySelectorAll( '[data-handik-pull-from-cal]' ).forEach( function( btn ) {
			const endpoint = btn.dataset.pullEndpoint || '';
			const nonce    = btn.dataset.restNonce    || '';
			if ( ! endpoint ) { return; }
			const status   = btn.parentElement
				? btn.parentElement.querySelector( '[data-handik-pull-from-cal-status]' )
				: null;
			btn.addEventListener( 'click', async function( event ) {
				event.preventDefault();
				if ( status ) {
					status.textContent = i18n.pullFromCalFetching || 'Fetching bookings from Cal.com…';
				}
				try {
					const result = await withButtonLoading( btn, function() {
						return window.fetch( endpoint, {
							method: 'POST',
							credentials: 'same-origin',
							headers: {
								'X-WP-Nonce': nonce,
								'Content-Type': 'application/json',
							},
							body: JSON.stringify( {} ),
						} ).then( function( response ) {
							return response.json().catch( function() { return {}; } ).then( function( payload ) {
								if ( ! response.ok ) {
									const error = new Error( payload.message || payload.code || 'Request failed' );
									error.status = response.status;
									throw error;
								}
								return payload;
							} );
						} );
					} );
					const fetched  = result && typeof result.fetched  === 'number' ? result.fetched  : 0;
					const inserted = result && typeof result.inserted === 'number' ? result.inserted : 0;
					const already  = result && typeof result.already_present === 'number' ? result.already_present : 0;
					const summary  = ( i18n.pullFromCalDone || 'Fetched %1$d · %2$d new · %3$d already there' )
						.replace( '%1$d', String( fetched ) )
						.replace( '%2$d', String( inserted ) )
						.replace( '%3$d', String( already ) );
					if ( status ) {
						status.textContent = summary;
					}
					toast( summary, 'success' );
					if ( inserted > 0 ) {
						window.setTimeout( function() {
							window.location.reload();
						}, 800 );
					}
				} catch ( err ) {
					const msg = ( err && err.message ) ? err.message : ( i18n.pullFromCalFailed || 'Pull from Cal.com failed' );
					if ( status ) {
						status.textContent = msg;
					}
					toast( msg, 'error', 4500 );
				}
			} );
		} );
	}

	// ============================================================
	// 2.1.26.0 (A4): bulk-delete "Abandoned drafts (24h+)" focus
	// list. The section renders with `data-handik-bulk-drafts`,
	// per-row `[data-handik-bulk-row value="<id>"]` checkboxes, a
	// "Select all" checkbox + "Delete selected" button at the top.
	// We post the collected ids to /admin/job-requests/bulk-delete
	// in one round-trip and reload on success.
	// ============================================================
	function initBulkDeleteDrafts() {
		document.querySelectorAll( '[data-handik-bulk-drafts]' ).forEach( function( section ) {
			const endpoint = section.dataset.bulkEndpoint || '';
			const nonce    = section.dataset.restNonce    || '';
			if ( ! endpoint ) { return; }
			const toggleAll = section.querySelector( '[data-handik-bulk-toggle-all]' );
			const rowChecks = Array.from( section.querySelectorAll( '[data-handik-bulk-row]' ) );
			const countEl   = section.querySelector( '[data-handik-bulk-count]' );
			const applyBtn  = section.querySelector( '[data-handik-bulk-apply]' );
			if ( ! applyBtn || ! rowChecks.length ) { return; }

			function refresh() {
				const checked = rowChecks.filter( function( c ) { return c.checked; } );
				const n = checked.length;
				if ( countEl ) {
					countEl.textContent = String( n ) + ' ' + ( i18n.selected || 'selected' );
				}
				applyBtn.disabled = 0 === n;
				if ( toggleAll ) {
					toggleAll.checked = n > 0 && n === rowChecks.length;
					toggleAll.indeterminate = n > 0 && n < rowChecks.length;
				}
			}

			if ( toggleAll ) {
				toggleAll.addEventListener( 'change', function() {
					rowChecks.forEach( function( c ) { c.checked = toggleAll.checked; } );
					refresh();
				} );
			}
			rowChecks.forEach( function( c ) {
				c.addEventListener( 'change', refresh );
			} );

			applyBtn.addEventListener( 'click', async function( event ) {
				event.preventDefault();
				const checked = rowChecks.filter( function( c ) { return c.checked; } );
				if ( 0 === checked.length ) { return; }
				const ids = checked.map( function( c ) { return parseInt( c.value, 10 ); } ).filter( function( v ) { return v > 0; } );
				const confirmMsg = ( i18n.bulkDeleteConfirm || 'Delete %d drafts? This is irreversible.' ).replace( '%d', String( ids.length ) );
				const ok = await openModal( {
					title: i18n.bulkDeleteTitle || 'Delete drafts',
					body: confirmMsg,
				} );
				if ( ! ok ) { return; }
				try {
					const result = await withButtonLoading( applyBtn, function() {
						return window.fetch( endpoint, {
							method: 'POST',
							credentials: 'same-origin',
							headers: {
								'X-WP-Nonce': nonce,
								'Content-Type': 'application/json',
							},
							body: JSON.stringify( { ids: ids } ),
						} ).then( function( response ) {
							return response.json().catch( function() { return {}; } ).then( function( payload ) {
								if ( ! response.ok ) {
									const error = new Error( payload.message || payload.code || 'Request failed' );
									error.status = response.status;
									throw error;
								}
								return payload;
							} );
						} );
					} );
					const deleted = result && typeof result.deleted === 'number' ? result.deleted : 0;
					toast(
						( i18n.bulkDeleteDone || 'Deleted %d drafts.' ).replace( '%d', String( deleted ) ),
						'success'
					);
					window.setTimeout( function() {
						window.location.reload();
					}, 600 );
				} catch ( err ) {
					const msg = ( err && err.message ) ? err.message : ( i18n.saveFailed || 'Delete failed' );
					toast( msg, 'error', 4500 );
				}
			} );

			refresh();
		} );
	}

}( window, document ) );
