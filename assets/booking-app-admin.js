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
			if ( opts.textarea ) {
				textarea = document.createElement( 'textarea' );
				textarea.placeholder = opts.placeholder || i18n.placeholder || '';
				textarea.value = opts.defaultValue || '';
				body.appendChild( textarea );
			}
			if ( opts.body ) {
				const p = document.createElement( 'p' );
				p.textContent = opts.body;
				body.appendChild( p );
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
				close( textarea ? textarea.value : true );
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
						const ok = await openModal( { title: i18n.cancelTitle || 'Cancel booking', body: i18n.confirmCancel || 'Are you sure?' } );
						if ( ! ok ) { return; }
						await adminFetch( ctx, 'admin/booking/' + bookingId + '/status', { body: { status: 'cancelled' } } );
						toast( i18n.saved || 'Saved', 'success' );
						window.location.reload();
					}
					if ( 'mark-completed' === action ) {
						const ok = await openModal( { title: i18n.completeTitle || 'Mark completed', body: i18n.confirmComplete || 'Are you sure?' } );
						if ( ! ok ) { return; }
						await adminFetch( ctx, 'admin/booking/' + bookingId + '/status', { body: { status: 'completed' } } );
						toast( i18n.saved || 'Saved', 'success' );
						window.location.reload();
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
						const ok = await openModal( { title: i18n.confirmDelete || 'Delete?', body: '' } );
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
						} ).filter( function( t ) { return t.id && t.label; } )
					};
				} ).filter( function( g ) { return g.group && g.tasks.length; } );
			}

			let saveTimer = null;
			function scheduleSave() {
				setStatus( 'saving', i18n.placeholder ? '…' : 'Saving…' );
				if ( saveTimer ) { window.clearTimeout( saveTimer ); }
				saveTimer = window.setTimeout( save, 600 );
			}

			function save() {
				const groups = serialize();
				adminFetch( editor, 'admin/catalog', { body: { groups: groups } } )
					.then( function() {
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
					if ( window.confirm( i18n.confirmDelete || 'Delete?' ) ) {
						const task = delTask.closest( '[data-handik-task]' );
						if ( task ) { task.remove(); scheduleSave(); }
					}
				}
				const delGroup = event.target.closest( '[data-handik-remove-group]' );
				if ( delGroup ) {
					event.preventDefault();
					if ( window.confirm( i18n.confirmDelete || 'Delete?' ) ) {
						const g = delGroup.closest( '[data-handik-group]' );
						if ( g ) { g.remove(); scheduleSave(); }
					}
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
							toast( 'DB version: ' + r.db_version, 'success' );
						}
						if ( 'clear-transients' === action ) {
							await adminFetch( ctx, 'admin/transients/clear', {} );
							toast( 'Transients cleared', 'success' );
						}
					} catch ( err ) {
						toast( i18n.saveFailed || 'Failed', 'error' );
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
				const img = document.createElement( 'img' );
				img.src = url;
				img.alt = '';
				backdrop.appendChild( img );
				backdrop.addEventListener( 'click', function() {
					if ( backdrop.parentNode ) { backdrop.parentNode.removeChild( backdrop ); }
				} );
				document.body.appendChild( backdrop );
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

	function initDebouncedSearch() {
		document.querySelectorAll( '[data-handik-debounced-submit]' ).forEach( function( input ) {
			let timer = null;
			input.addEventListener( 'input', function() {
				if ( timer ) { window.clearTimeout( timer ); }
				timer = window.setTimeout( function() {
					const form = input.closest( 'form' );
					if ( form ) { form.submit(); }
				}, 350 );
			} );
		} );
	}

	// ============================================================
	// Boot
	// ============================================================

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
	} );

}( window, document ) );
