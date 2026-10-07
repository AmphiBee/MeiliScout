import { __, sprintf } from '@wordpress/i18n';

const locale = document.documentElement.lang || undefined;

export const number = ( value ) =>
	new Intl.NumberFormat( locale ).format( value ?? 0 );

/**
 * A size in bytes, in the largest unit that keeps it above 1.
 *
 * @param {number|null} bytes
 * @return {string} The size, or a dash when unknown.
 */
export const size = ( bytes ) => {
	if ( bytes === null || bytes === undefined ) {
		return '—';
	}

	const units = [ 'byte', 'kilobyte', 'megabyte', 'gigabyte' ];
	let value = bytes;
	let unit = 0;

	while ( value >= 1024 && unit < units.length - 1 ) {
		value /= 1024;
		unit++;
	}

	return new Intl.NumberFormat( locale, {
		style: 'unit',
		unit: units[ unit ],
		unitDisplay: 'short',
		maximumFractionDigits: unit < 2 ? 0 : 1,
	} ).format( value );
};

export const duration = ( ms ) => {
	if ( ms < 1000 ) {
		return sprintf(
			/* translators: %s: a duration in milliseconds */
			__( '%s ms', 'meiliscout' ),
			number( Math.round( ms ) )
		);
	}

	return new Intl.NumberFormat( locale, {
		style: 'unit',
		unit: 'second',
		unitDisplay: 'narrow',
		maximumFractionDigits: ms < 10000 ? 1 : 0,
	} ).format( ms / 1000 );
};

const relative = new Intl.RelativeTimeFormat( locale, { numeric: 'auto' } );

/**
 * A date, relative to now for the last week, as a date beyond.
 *
 * @param {Date} date
 * @return {string} The formatted date.
 */
const fromDate = ( date ) => {
	const seconds = Math.round( ( date.getTime() - Date.now() ) / 1000 );
	const abs = Math.abs( seconds );

	if ( abs < 60 ) {
		return relative.format( 0, 'second' );
	}
	if ( abs < 3600 ) {
		return relative.format( Math.round( seconds / 60 ), 'minute' );
	}
	if ( abs < 86400 ) {
		return relative.format( Math.round( seconds / 3600 ), 'hour' );
	}
	if ( abs < 7 * 86400 ) {
		return relative.format( Math.round( seconds / 86400 ), 'day' );
	}

	return new Intl.DateTimeFormat( locale, {
		weekday: 'short',
		day: 'numeric',
		month: 'short',
		hour: '2-digit',
		minute: '2-digit',
	} ).format( date );
};

/**
 * @param {number} timestamp Unix time, in seconds.
 * @return {string} The formatted date.
 */
export const ago = ( timestamp ) => fromDate( new Date( timestamp * 1000 ) );

/**
 * @param {string|null} iso An ISO 8601 date.
 * @return {string} The formatted date, or a dash.
 */
export const agoIso = ( iso ) => ( iso ? fromDate( new Date( iso ) ) : '—' );
