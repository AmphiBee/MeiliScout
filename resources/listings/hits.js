/**
 * The client transport's cards and pagination: the client's half of
 * Listings\Render\Hits and Renderer::pageItems(), written the same way
 * (tests/fixtures/listings/card-cases.json).
 */

const ENTITIES = {
	amp: '&',
	lt: '<',
	gt: '>',
	quot: '"',
	apos: "'",
	nbsp: ' ',
	hellip: '…',
	ndash: '–',
	mdash: '—',
	lsquo: '‘',
	rsquo: '’',
	ldquo: '“',
	rdquo: '”',
	laquo: '«',
	raquo: '»',
};

/**
 * As html_entity_decode(): numeric entities and the named ones titles carry.
 * In a browser, the parser knows them all.
 *
 * @param {*} value
 * @return {string} The text.
 */
export const decodeEntities = ( value ) => {
	const text = value === null || value === undefined ? '' : String( value );
	if ( ! text.includes( '&' ) ) {
		return text;
	}
	if ( typeof document !== 'undefined' ) {
		const area = document.createElement( 'textarea' );
		area.innerHTML = text;
		return area.value;
	}
	return text.replace( /&(#x[0-9a-f]+|#\d+|[a-z]+);/gi, ( entity, name ) => {
		if ( name[ 0 ] === '#' ) {
			return String.fromCodePoint(
				name[ 1 ].toLowerCase() === 'x'
					? parseInt( name.slice( 2 ), 16 )
					: parseInt( name.slice( 1 ), 10 )
			);
		}
		return ENTITIES[ name.toLowerCase() ] ?? entity;
	} );
};

/**
 * As PHP's trim(): ASCII whitespace and NUL only.
 *
 * @param {string} value
 * @return {string} The trimmed value.
 */
const trim = ( value ) =>
	value.replace( /^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '' );

const pad = ( n ) => String( n ).padStart( 2, '0' );

const suffix = ( day ) => {
	if ( day % 100 >= 11 && day % 100 <= 13 ) {
		return 'th';
	}
	return [ 'th', 'st', 'nd', 'rd' ][ day % 10 ] ?? 'th';
};

/**
 * A post's date (Y-m-d H:i:s, the site's time) in a PHP date format, with the
 * site's names: Hits::formatDate().
 *
 * @param {Object} names Format and names (Hits::dateNames()).
 * @param {string} date
 * @return {string} The date.
 */
export const formatDate = ( names, date ) => {
	const m = /^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}):(\d{2}))?/.exec(
		date || ''
	);
	if ( ! m ) {
		return '';
	}
	const [ year, month, day ] = [ +m[ 1 ], +m[ 2 ], +m[ 3 ] ];
	const [ hour, minute, second ] = [
		+( m[ 4 ] || 0 ),
		+( m[ 5 ] || 0 ),
		+( m[ 6 ] || 0 ),
	];
	const weekday = new Date( Date.UTC( year, month - 1, day ) ).getUTCDay();
	const hour12 = hour % 12 === 0 ? 12 : hour % 12;
	const meridiem = hour < 12 ? 'am' : 'pm';
	const tokens = {
		d: () => pad( day ),
		j: () => String( day ),
		D: () => names.weekdaysShort[ weekday ] ?? '',
		l: () => names.weekdays[ weekday ] ?? '',
		N: () => String( weekday === 0 ? 7 : weekday ),
		w: () => String( weekday ),
		S: () => suffix( day ),
		F: () => names.months[ month - 1 ] ?? '',
		M: () => names.monthsShort[ month - 1 ] ?? '',
		m: () => pad( month ),
		n: () => String( month ),
		Y: () => String( year ),
		y: () => pad( year % 100 ),
		a: () => names.meridiem?.[ meridiem ] ?? meridiem,
		A: () =>
			names.meridiem?.[ meridiem.toUpperCase() ] ??
			meridiem.toUpperCase(),
		g: () => String( hour12 ),
		h: () => pad( hour12 ),
		G: () => String( hour ),
		H: () => pad( hour ),
		i: () => pad( minute ),
		s: () => pad( second ),
	};
	let out = '';
	for ( let i = 0; i < names.format.length; i++ ) {
		const char = names.format[ i ];
		if ( char === '\\' ) {
			out += names.format[ ++i ] ?? '';
			continue;
		}
		out += tokens[ char ] ? tokens[ char ]() : char;
	}
	return out;
};

/**
 * As Hits::scalar(): a meta value as text.
 *
 * @param {*} value
 * @return {string} The text.
 */
const scalar = ( value ) => {
	if ( typeof value === 'boolean' ) {
		return value ? '1' : '';
	}
	if ( typeof value === 'number' || typeof value === 'string' ) {
		return String( value );
	}
	return '';
};

/**
 * A card from a document: Hits::fromDocument().
 *
 * @param {Object}   document A hit, with _formatted.content_text cropped.
 * @param {string[]} metas    The listing's public meta keys.
 * @param {Object}   names    Date format and names.
 * @return {Object} The card.
 */
export const hitFromDocument = ( document, metas, names ) => {
	let excerpt = trim( decodeEntities( document.post_excerpt ) );
	if ( excerpt === '' ) {
		excerpt = trim(
			decodeEntities( document._formatted?.content_text ?? '' )
		);
	}

	const terms = {};
	for ( const [ taxonomy, list ] of Object.entries(
		document.taxonomies || {}
	) ) {
		terms[ taxonomy ] = ( list || [] ).map( ( term ) => ( {
			name: decodeEntities( term.name ),
			slug: String( term.slug ?? '' ),
		} ) );
	}

	const values = {};
	for ( const key of metas ) {
		const value = document.metas?.[ key ] ?? '';
		values[ key ] = Array.isArray( value )
			? value.map( scalar )
			: scalar( value );
	}

	const date = String( document.post_date ?? '' );

	return {
		id: Number( document.ID ?? 0 ),
		url: String( document.url ?? '' ),
		title: decodeEntities( document.post_title ),
		excerpt,
		date,
		dateLabel: date === '' ? '' : formatDate( names, date ),
		type: String( document.post_type ?? '' ),
		terms,
		metas: values,
	};
};

/**
 * The pagination's items: Renderer::pageItems().
 *
 * @param {number} page
 * @param {number} pages
 * @return {Array<{kind: string, page: number, current: boolean}>} The items.
 */
export const pageItems = ( page, pages ) => {
	if ( pages < 2 ) {
		return [];
	}
	const current = Math.min( Math.max( 1, page ), pages );
	const items = [];
	if ( current > 1 ) {
		items.push( { kind: 'previous', page: current - 1, current: false } );
	}
	let previous = 0;
	for ( let n = 1; n <= pages; n++ ) {
		if ( n !== 1 && n !== pages && Math.abs( n - current ) > 2 ) {
			continue;
		}
		if ( n - previous > 1 ) {
			items.push( { kind: 'dots', page: 0, current: false } );
		}
		items.push( { kind: 'number', page: n, current: n === current } );
		previous = n;
	}
	if ( current < pages ) {
		items.push( { kind: 'next', page: current + 1, current: false } );
	}
	return items;
};

/**
 * The pagination's links as the region binds them: Store::pageLinks().
 *
 * @param {Array}    items  pageItems().
 * @param {Function} url    A page's URL.
 * @param {Object}   labels Previous and next.
 * @return {Object[]} The links.
 */
export const pageLinks = ( items, url, labels ) =>
	items.map( ( item, i ) => {
		const labelOf = {
			previous: labels.previous,
			next: labels.next,
			dots: '…',
		};
		return {
			key: item.kind + '-' + ( item.kind === 'dots' ? i : item.page ),
			label: labelOf[ item.kind ] ?? String( item.page ),
			url: item.kind === 'dots' || item.current ? null : url( item.page ),
			current: item.current ? 'page' : null,
			hidden: item.kind === 'dots' ? 'true' : null,
			className:
				'meiliscout-pagination__link meiliscout-pagination__' +
				item.kind,
		};
	} );
