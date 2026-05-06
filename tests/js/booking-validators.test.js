/**
 * Unit tests for assets/booking-validators.js.
 *
 * The validators run client-side on every keystroke and on every Continue
 * press, so they sit on the hot path of the entire booking flow. A regression
 * here (e.g. a stricter regex that rejects valid US phone shapes) directly
 * blocks bookings, which is why we cover the matrix here.
 */

const validators = require( '../../assets/booking-validators.js' );

describe( 'validateFullName', () => {
	test.each( [
		[ 'Alex', true ],
		[ 'Alex Smith', true ],
		[ 'Mary-Jane', true ],
		[ "O'Connor", true ],
		[ 'Иван', true ], // Cyrillic letters allowed (\p{L}).
		[ '李雷', true ],   // CJK letters allowed.
		[ 'Анна Мария', true ],
		[ '  Alex  ', true ], // Leading/trailing whitespace is trimmed.
	] )( 'accepts %j', ( input, expected ) => {
		expect( validators.validateFullName( input ) ).toBe( expected );
	} );

	test.each( [
		[ '', false ],
		[ '   ', false ],
		[ '123', false ],
		[ 'Alex123', false ],
		[ '-Alex', false ],
		[ "'Alex", false ],
		[ '@lex', false ],
		[ null, false ],
		[ undefined, false ],
	] )( 'rejects %j', ( input, expected ) => {
		expect( validators.validateFullName( input ) ).toBe( expected );
	} );
} );

describe( 'validateEmail', () => {
	test.each( [
		'alex@example.com',
		'alex+work@example.co.uk',
		'a@b.co',
		'first.last@sub.domain.io',
	] )( 'accepts %s', ( email ) => {
		expect( validators.validateEmail( email ) ).toBe( true );
	} );

	test.each( [
		'',
		'plainaddress',
		'@nodomain.com',
		'no@dot',
		'has space@x.com',
		'two@@x.com',
		'trailing@x.',
	] )( 'rejects %j', ( email ) => {
		expect( validators.validateEmail( email ) ).toBe( false );
	} );

	test( 'handles null/undefined', () => {
		expect( validators.validateEmail( null ) ).toBe( false );
		expect( validators.validateEmail( undefined ) ).toBe( false );
	} );
} );

describe( 'phoneDigits', () => {
	test( 'strips non-digits', () => {
		expect( validators.phoneDigits( '(555) 123-4567' ) ).toBe( '5551234567' );
		expect( validators.phoneDigits( '+1 555 123 4567' ) ).toBe( '5551234567' );
	} );

	test( 'drops leading 1 country code when total > 10 digits', () => {
		expect( validators.phoneDigits( '15551234567' ) ).toBe( '5551234567' );
		expect( validators.phoneDigits( '+1-555-123-4567' ) ).toBe( '5551234567' );
	} );

	test( 'caps at 10 digits', () => {
		expect( validators.phoneDigits( '555123456789' ) ).toBe( '5551234567' );
	} );

	test( 'returns empty for empty input', () => {
		expect( validators.phoneDigits( '' ) ).toBe( '' );
		expect( validators.phoneDigits( null ) ).toBe( '' );
		expect( validators.phoneDigits( undefined ) ).toBe( '' );
	} );

	test( 'short input passes through', () => {
		expect( validators.phoneDigits( '555' ) ).toBe( '555' );
	} );
} );

describe( 'formatPhoneDisplay', () => {
	test( 'formats full number into +1 NNN NNN NN NN', () => {
		expect( validators.formatPhoneDisplay( '5551234567' ) ).toBe( '+1 555 123 45 67' );
	} );

	test( 'formats progressively as the user types', () => {
		expect( validators.formatPhoneDisplay( '5' ) ).toBe( '+1 5' );
		expect( validators.formatPhoneDisplay( '555' ) ).toBe( '+1 555' );
		expect( validators.formatPhoneDisplay( '5551' ) ).toBe( '+1 555 1' );
		expect( validators.formatPhoneDisplay( '555123' ) ).toBe( '+1 555 123' );
		expect( validators.formatPhoneDisplay( '5551234' ) ).toBe( '+1 555 123 4' );
		expect( validators.formatPhoneDisplay( '55512345' ) ).toBe( '+1 555 123 45' );
		expect( validators.formatPhoneDisplay( '555123456' ) ).toBe( '+1 555 123 45 6' );
	} );

	test( 'returns empty string for empty input', () => {
		expect( validators.formatPhoneDisplay( '' ) ).toBe( '' );
		expect( validators.formatPhoneDisplay( null ) ).toBe( '' );
	} );

	test( 'normalizes already-formatted input', () => {
		expect( validators.formatPhoneDisplay( '+1 (555) 123-4567' ) ).toBe( '+1 555 123 45 67' );
	} );
} );

describe( 'phoneApiValue', () => {
	test( 'returns E.164 +1 prefix for full numbers', () => {
		expect( validators.phoneApiValue( '5551234567' ) ).toBe( '+15551234567' );
		expect( validators.phoneApiValue( '(555) 123-4567' ) ).toBe( '+15551234567' );
	} );

	test( 'returns empty string when input is incomplete', () => {
		expect( validators.phoneApiValue( '555123' ) ).toBe( '' );
		expect( validators.phoneApiValue( '' ) ).toBe( '' );
	} );
} );

describe( 'validatePhone', () => {
	test( 'true only when 10 digits collected', () => {
		expect( validators.validatePhone( '5551234567' ) ).toBe( true );
		expect( validators.validatePhone( '+1 (555) 123-4567' ) ).toBe( true );
		expect( validators.validatePhone( '15551234567' ) ).toBe( true );
		expect( validators.validatePhone( '555123456' ) ).toBe( false );
		expect( validators.validatePhone( '' ) ).toBe( false );
		expect( validators.validatePhone( null ) ).toBe( false );
	} );
} );

describe( 'global registration', () => {
	test( 'attaches to window in browser environments', () => {
		// Set up a stub window, re-load the module, and confirm the global
		// surface area is what the SPA expects.
		const win = {};
		const prev = global.window;
		global.window = win;
		jest.resetModules();
		require( '../../assets/booking-validators.js' );
		expect( typeof win.HandikBookingValidators.validateEmail ).toBe( 'function' );
		expect( typeof win.HandikBookingValidators.formatPhoneDisplay ).toBe( 'function' );
		global.window = prev;
	} );
} );
