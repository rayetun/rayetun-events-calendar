import apiFetch from '@wordpress/api-fetch';
import { createContext, useContext } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export const config = window.rayecAdmin || {};

const BASE = '/rayec/v1';

export const api = {
	get: ( path ) => apiFetch( { path: BASE + path } ),
	post: ( path, data ) =>
		apiFetch( { path: BASE + path, method: 'POST', data } ),
	del: ( path ) => apiFetch( { path: BASE + path, method: 'DELETE' } ),
};

/** App-wide actions: toasts, opening the event editor, refreshing data. */
export const AppContext = createContext( {} );
export const useApp = () => useContext( AppContext );

/* ---------------------------------------------------------------------------
 * Dates. Everything is a plain "YYYY-MM-DD" or "YYYY-MM-DDTHH:MM" string in the
 * event's own wall-clock time; UTC Date objects are used only for arithmetic.
 * ------------------------------------------------------------------------ */

const pad = ( n ) => String( n ).padStart( 2, '0' );

export const toDate = ( ymd ) => {
	const [ y, m, d ] = ymd.slice( 0, 10 ).split( '-' ).map( Number );
	return new Date( Date.UTC( y, m - 1, d ) );
};

export const toYmd = ( date ) =>
	`${ date.getUTCFullYear() }-${ pad( date.getUTCMonth() + 1 ) }-${ pad(
		date.getUTCDate()
	) }`;

export const addDays = ( ymd, days ) => {
	const date = toDate( ymd );
	date.setUTCDate( date.getUTCDate() + days );
	return toYmd( date );
};

export const addMonths = ( ymd, months ) => {
	const date = toDate( ymd.slice( 0, 7 ) + '-01' );
	date.setUTCMonth( date.getUTCMonth() + months );
	return toYmd( date );
};

/** Today in the site's time zone. */
export const today = () => {
	try {
		const parts = new Intl.DateTimeFormat( 'en-CA', {
			timeZone: config.timezone,
			year: 'numeric',
			month: '2-digit',
			day: '2-digit',
		} ).format( new Date() );
		if ( /^\d{4}-\d{2}-\d{2}$/.test( parts ) ) {
			return parts;
		}
	} catch ( e ) {
		// Offsets such as "+06:00" are not valid Intl zones; fall through.
	}
	const now = new Date();
	return `${ now.getFullYear() }-${ pad( now.getMonth() + 1 ) }-${ pad(
		now.getDate()
	) }`;
};

export const formatDate = ( ymd, options ) =>
	new Intl.DateTimeFormat( config.locale || undefined, {
		timeZone: 'UTC',
		...options,
	} ).format( toDate( ymd ) );

/**
 * What an Accessibility Guard level is called where someone has to read it.
 *
 * "error" fails a WCAG success criterion, "warning" is a likely barrier, and
 * "note" is worth a look but may well be fine as it is.
 *
 * @param {string} level Issue level.
 * @return {string} A label ending in a colon.
 */
export const guardLevel = ( level ) => {
	if ( 'error' === level ) {
		return __( 'Problem:', 'rayetun-events-calendar' );
	}
	if ( 'note' === level ) {
		return __( 'Worth a look:', 'rayetun-events-calendar' );
	}
	return __( 'Suggestion:', 'rayetun-events-calendar' );
};

export const weekdayNames = ( format = 'short' ) => {
	// 2024-01-07 is a Sunday.
	return Array.from( { length: 7 }, ( _, i ) =>
		formatDate( addDays( '2024-01-07', i ), { weekday: format } )
	);
};

/* ---------------------------------------------------------------------------
 * Recurrence rules <-> form state.
 * ------------------------------------------------------------------------ */

export const DAY_CODES = [ 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA' ];

export const emptyRule = {
	freq: '',
	interval: 1,
	days: [],
	monthlyMode: 'date',
	ends: 'never',
	until: '',
	count: 10,
};

export const parseRule = ( rrule ) => {
	const rule = { ...emptyRule };
	if ( ! rrule ) {
		return rule;
	}
	rrule
		.replace( /^RRULE:/i, '' )
		.split( ';' )
		.forEach( ( part ) => {
			const [ key, value = '' ] = part.split( '=' );
			switch ( key.toUpperCase() ) {
				case 'FREQ':
					rule.freq = value.toUpperCase();
					break;
				case 'INTERVAL':
					rule.interval = Math.max( 1, parseInt( value, 10 ) || 1 );
					break;
				case 'BYDAY':
					if ( /\d/.test( value ) ) {
						rule.monthlyMode = 'weekday';
					} else {
						rule.days = value.toUpperCase().split( ',' );
					}
					break;
				case 'COUNT':
					rule.ends = 'count';
					rule.count = Math.max( 1, parseInt( value, 10 ) || 1 );
					break;
				case 'UNTIL':
					rule.ends = 'until';
					rule.until = `${ value.slice( 0, 4 ) }-${ value.slice(
						4,
						6
					) }-${ value.slice( 6, 8 ) }`;
					break;
			}
		} );
	return rule;
};

/** Which "nth weekday" a date is, e.g. { ordinal: 2, code: 'TU' }. */
export const nthWeekday = ( ymd ) => {
	const date = toDate( ymd );
	const day = date.getUTCDate();
	const next = new Date( date );
	next.setUTCDate( day + 7 );
	const isLast = next.getUTCMonth() !== date.getUTCMonth();
	const ordinal = Math.ceil( day / 7 );
	return {
		ordinal: ordinal > 4 || ( isLast && ordinal === 5 ) ? -1 : ordinal,
		code: DAY_CODES[ date.getUTCDay() ],
		isLast,
	};
};

export const buildRule = ( rule, startYmd ) => {
	if ( ! rule.freq ) {
		return '';
	}
	const parts = [ `FREQ=${ rule.freq }` ];
	if ( rule.interval > 1 ) {
		parts.push( `INTERVAL=${ rule.interval }` );
	}
	if ( rule.freq === 'WEEKLY' && rule.days.length ) {
		const ordered = [ 'MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU' ].filter(
			( code ) => rule.days.includes( code )
		);
		parts.push( `BYDAY=${ ordered.join( ',' ) }` );
	}
	if (
		rule.freq === 'MONTHLY' &&
		rule.monthlyMode === 'weekday' &&
		startYmd
	) {
		const nth = nthWeekday( startYmd );
		parts.push( `BYDAY=${ nth.ordinal }${ nth.code }` );
	}
	if ( rule.ends === 'count' ) {
		parts.push( `COUNT=${ rule.count }` );
	} else if ( rule.ends === 'until' && rule.until ) {
		parts.push( `UNTIL=${ rule.until.replace( /-/g, '' ) }` );
	}
	return parts.join( ';' );
};
