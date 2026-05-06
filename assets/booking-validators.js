/**
 * Pure validation + phone-formatting helpers used by the public booking SPA.
 *
 * Lives in its own file so they can be unit-tested in Node (Jest) without
 * spinning up the full BookingApp class or jsdom. Loaded as a UMD-ish module:
 * the global `window.HandikBookingValidators` is the production entry point;
 * `module.exports` is the test entry point.
 *
 * Keep this file dependency-free. Anything that needs `this.state`, the DOM,
 * or the global config belongs in booking-app.js.
 */
( function ( factory ) {
	'use strict';
	var api = factory();
	if ( typeof window !== 'undefined' ) {
		window.HandikBookingValidators = api;
	}
	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api;
	}
} )( function () {
	'use strict';

	function validateFullName( value ) {
		var normalized = String( value == null ? '' : value ).trim();
		return /^[\p{L}][\p{L}\s'-]*$/u.test( normalized );
	}

	function validateEmail( value ) {
		return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( String( value == null ? '' : value ).trim() );
	}

	function phoneDigits( value ) {
		var digits = String( value == null ? '' : value ).replace( /\D/g, '' );
		if ( digits.length > 10 && '1' === digits.charAt( 0 ) ) {
			digits = digits.slice( 1 );
		}
		return digits.slice( 0, 10 );
	}

	function formatPhoneDisplay( value ) {
		var digits = phoneDigits( value );
		if ( ! digits ) {
			return '';
		}
		var parts = [];
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

	function phoneApiValue( value ) {
		var digits = phoneDigits( value );
		return 10 === digits.length ? '+1' + digits : '';
	}

	function validatePhone( value ) {
		return 10 === phoneDigits( value ).length;
	}

	return {
		validateFullName: validateFullName,
		validateEmail: validateEmail,
		phoneDigits: phoneDigits,
		formatPhoneDisplay: formatPhoneDisplay,
		phoneApiValue: phoneApiValue,
		validatePhone: validatePhone,
	};
} );
