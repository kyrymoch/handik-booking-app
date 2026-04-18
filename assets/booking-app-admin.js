( function() {
	'use strict';

	function serializeCatalog( editor ) {
		const groups = Array.from( editor.querySelectorAll( '[data-handik-group]' ) ).map( function( group ) {
			return {
				group: ( group.querySelector( '[data-handik-group-name]' ) || {} ).value || '',
				tasks: Array.from( group.querySelectorAll( '[data-handik-task]' ) ).map( function( task ) {
					return {
						id: ( task.querySelector( '[data-handik-task-id]' ) || {} ).value || '',
						label: ( task.querySelector( '[data-handik-task-label]' ) || {} ).value || '',
						description: ( task.querySelector( '[data-handik-task-description]' ) || {} ).value || '',
						rate_label: ( task.querySelector( '[data-handik-task-rate]' ) || {} ).value || '',
						service_family: ( task.querySelector( '[data-handik-task-service-family]' ) || {} ).value || '',
						rate_family: ( task.querySelector( '[data-handik-task-rate-family]' ) || {} ).value || ''
					};
				} ).filter( function( task ) {
					return task.id && task.label;
				} )
			};
		} ).filter( function( group ) {
			return group.group && group.tasks.length;
		} );

		const target = editor.querySelector( '[data-handik-catalog-json]' );
		if ( target ) {
			target.value = JSON.stringify( groups, null, 2 );
		}
	}

	function taskTemplate() {
		return '' +
		'<div class="handik-catalog-task" data-handik-task>' +
			'<div class="handik-admin-grid">' +
				'<label><span>Service ID</span><input type="text" data-handik-task-id /></label>' +
				'<label><span>Label</span><input type="text" data-handik-task-label /></label>' +
				'<label><span>Hourly price hint</span><input type="text" data-handik-task-rate /></label>' +
				'<label><span>Service family</span><input type="text" data-handik-task-service-family /></label>' +
				'<label><span>Rate family</span><input type="text" data-handik-task-rate-family /></label>' +
			'</div>' +
			'<label style="display:grid;gap:8px;"><span>Client-facing description</span><textarea rows="2" data-handik-task-description></textarea></label>' +
			'<p><button type="button" class="button-link-delete" data-handik-remove-task>Remove service</button></p>' +
		'</div>';
	}

	function groupTemplate() {
		return '' +
		'<div class="handik-catalog-group" data-handik-group>' +
			'<div class="handik-catalog-group__header">' +
				'<label><span>Category title</span><input type="text" data-handik-group-name /></label>' +
				'<button type="button" class="button-link-delete" data-handik-remove-group>Remove category</button>' +
			'</div>' +
			'<div class="handik-catalog-group__tasks">' + taskTemplate() + '</div>' +
			'<p><button type="button" class="button button-secondary" data-handik-add-task>Add service</button></p>' +
		'</div>';
	}

	document.addEventListener( 'DOMContentLoaded', function() {
		document.querySelectorAll( '[data-handik-catalog-editor]' ).forEach( function( editor ) {
			editor.addEventListener( 'click', function( event ) {
				const addGroup = event.target.closest( '[data-handik-add-group]' );
				const addTask = event.target.closest( '[data-handik-add-task]' );
				const removeTask = event.target.closest( '[data-handik-remove-task]' );
				const removeGroup = event.target.closest( '[data-handik-remove-group]' );

				if ( addGroup ) {
					event.preventDefault();
					editor.querySelector( '.handik-catalog-editor__groups' ).insertAdjacentHTML( 'beforeend', groupTemplate() );
					serializeCatalog( editor );
				}

				if ( addTask ) {
					event.preventDefault();
					addTask.closest( '[data-handik-group]' ).querySelector( '.handik-catalog-group__tasks' ).insertAdjacentHTML( 'beforeend', taskTemplate() );
					serializeCatalog( editor );
				}

				if ( removeTask ) {
					event.preventDefault();
					const task = removeTask.closest( '[data-handik-task]' );
					if ( task ) {
						task.remove();
					}
					serializeCatalog( editor );
				}

				if ( removeGroup ) {
					event.preventDefault();
					const group = removeGroup.closest( '[data-handik-group]' );
					if ( group ) {
						group.remove();
					}
					serializeCatalog( editor );
				}
			} );

			editor.addEventListener( 'input', function() {
				serializeCatalog( editor );
			} );

			const form = editor.closest( 'form' );
			if ( form ) {
				form.addEventListener( 'submit', function() {
					serializeCatalog( editor );
				} );
			}

			serializeCatalog( editor );
		} );
	} );
}() );
