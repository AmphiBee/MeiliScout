/**
 * A listing's state to and from the URL: the client's half of
 * Listings\State\UrlCodec, written the same way (tests/fixtures/listings/url-cases.json).
 *
 * The template (PlanTemplate) gives the facets in order, their parameter,
 * type and decimals, and the sort and search parameters.
 */

/**
 * As PHP's rawurlencode(): encodeURIComponent() leaves ! ' ( ) * as they are.
 *
 * @param {string} value
 * @return {string} The encoded value.
 */
export const rawurlencode = ( value ) =>
	encodeURIComponent( value ).replace(
		/[!'()*]/g,
		( c ) => '%' + c.charCodeAt( 0 ).toString( 16 ).toUpperCase()
	);

/**
 * Byte order, as PHP's sort( SORT_STRING ): UTF-8 keeps the order of code
 * points, which UTF-16 units do not (surrogates sort before U+E000).
 *
 * @param {string} a
 * @param {string} b
 * @return {number} The order.
 */
export const byteCompare = ( a, b ) => {
	const ca = Array.from( a, ( c ) => c.codePointAt( 0 ) );
	const cb = Array.from( b, ( c ) => c.codePointAt( 0 ) );
	for ( let i = 0; i < Math.min( ca.length, cb.length ); i++ ) {
		if ( ca[ i ] !== cb[ i ] ) {
			return ca[ i ] - cb[ i ];
		}
	}
	return ca.length - cb.length;
};

/**
 * Moves the decimal point of a number written in base 10, without the binary
 * error of a multiplication (1.005 * 100 is 100.49999999999999).
 *
 * @param {number} value
 * @param {number} places
 * @return {number} The number.
 */
const shift = ( value, places ) => {
	const [ mantissa, exponent = '0' ] = String( value ).split( 'e' );
	return Number( `${ mantissa }e${ Number( exponent ) + places }` );
};

/**
 * As PHP's round() (8.4): half away from zero, on the number as written.
 *
 * @param {number} value
 * @param {number} decimals
 * @return {number} The rounded number.
 */
export const round = ( value, decimals ) => {
	const n = Number( value );
	if ( ! Number.isFinite( n ) ) {
		return NaN;
	}
	const rounded = shift(
		Math.round( shift( Math.abs( n ), decimals ) ),
		-decimals
	);
	return n < 0 && rounded !== 0 ? -rounded : rounded;
};

/**
 * A bound as UrlCodec::number() writes it: 1500, 1500.5.
 *
 * @param {number|null|undefined} value
 * @param {number}                decimals
 * @return {string} The bound.
 */
export const formatNumber = ( value, decimals ) =>
	value === null || value === undefined || Number.isNaN( Number( value ) )
		? ''
		: String( round( value, decimals ) );

/**
 * As PHP's urldecode(): + is a space, a malformed sequence stays as it is.
 *
 * @param {string} part
 * @return {string} The decoded part.
 */
const decode = ( part ) => {
	const spaced = part.replace( /\+/g, ' ' );
	try {
		return decodeURIComponent( spaced );
	} catch {
		return spaced.replace( /(?:%[0-9a-f]{2})+/gi, ( run ) => {
			try {
				return decodeURIComponent( run );
			} catch {
				return run;
			}
		} );
	}
};

/**
 * Letters remove_accents() writes as others, beyond a base letter and its accents.
 */
const LIGATURES = {
	æ: 'ae',
	œ: 'oe',
	ø: 'o',
	ß: 'ss',
	đ: 'd',
	ð: 'd',
	þ: 'th',
	ł: 'l',
	ŀ: 'l',
	ĳ: 'ij',
	ı: 'i',
	ĸ: 'k',
	ŉ: 'n',
	ŋ: 'n',
	ſ: 's',
};

/**
 * WordPress's sanitize_title() (remove_accents(), then
 * sanitize_title_with_dashes() as it saves a slug): Latin letters without
 * their accents, other characters percent-encoded in lowercase, dots and
 * spaces as dashes, anything else dropped.
 *
 * @param {string} value
 * @return {string} The slug.
 */
export const sanitizeTitle = ( value ) =>
	value
		.replace( /<[^>]*>/g, '' )
		// Only a Latin letter loses its accents: й stays й
		.normalize( 'NFD' )
		.replace( /([a-z])[\u0300-\u036f]+/gi, '$1' )
		.normalize( 'NFC' )
		.toLowerCase()
		.replace( /[æœøßđðþłŀĳıĸŉŋſ]/g, ( c ) => LIGATURES[ c ] )
		// A percent sign stays only as the start of an encoded byte
		.replace( /%(?![0-9a-f]{2})/gi, '' )
		// No-break space, en and em dashes: dashes
		.replace( /[\u00a0\u2013\u2014]/g, '-' )
		.replace( /[^\x00-\x7f]+/gu, ( c ) =>
			encodeURIComponent( c ).toLowerCase()
		)
		.toLowerCase()
		.replace( /\./g, '-' )
		.replace( /[^%a-z0-9 _-]/g, '' )
		.replace( /\s+/g, '-' )
		.replace( /-+/g, '-' )
		.replace( /^-|-$/g, '' );

/**
 * As PHP's trim(): ASCII whitespace and NUL only.
 *
 * @param {string} value
 * @return {string} The trimmed value.
 */
const trim = ( value ) =>
	value.replace( /^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '' );

const last = ( values ) => ( values.length ? values[ values.length - 1 ] : '' );

const RANGE = /^(-?\d+(?:\.\d+)?)?\.\.(-?\d+(?:\.\d+)?)?$/;

/**
 * Each parameter's values, split on bare commas, decoded.
 *
 * @param {string} query The query string, without ?.
 * @return {Object<string, string[]>} The values, by name.
 */
const parseRaw = ( query ) => {
	const raw = {};
	for ( const pair of query.split( '&' ) ) {
		if ( pair === '' ) {
			continue;
		}
		const at = pair.indexOf( '=' );
		const name = decode( at === -1 ? pair : pair.slice( 0, at ) );
		const value = at === -1 ? '' : pair.slice( at + 1 );
		raw[ name ] = [
			...( raw[ name ] || [] ),
			...value.split( ',' ).map( decode ),
		];
	}
	return raw;
};

/**
 * The state a query string asks for.
 *
 * @param {Object} template
 * @param {string} query
 * @param {number} page
 * @return {{values: Object, ranges: Object, sort: string, page: number, search: string}} The state.
 */
export const parse = ( template, query, page = 1 ) => {
	const raw = parseRaw( query );
	const values = {};
	const ranges = {};

	for ( const facet of template.facets ) {
		let parts = raw[ facet.param ] || [];

		if ( facet.type === 'range' && ! parts.length ) {
			const min = last( raw[ facet.param + '_min' ] || [] );
			const max = last( raw[ facet.param + '_max' ] || [] );
			parts = min === '' && max === '' ? [] : [ min + '..' + max ];
		}
		if ( ! parts.length ) {
			continue;
		}

		if ( facet.type === 'range' ) {
			const m = last( parts ).match( RANGE );
			if ( m ) {
				const range = {};
				if ( m[ 1 ] !== undefined ) {
					range.min = round( m[ 1 ], facet.decimals );
				}
				if ( m[ 2 ] !== undefined ) {
					range.max = round( m[ 2 ], facet.decimals );
				}
				if (
					range.min !== undefined &&
					range.max !== undefined &&
					range.min > range.max
				) {
					[ range.min, range.max ] = [ range.max, range.min ];
				}
				if ( Object.keys( range ).length ) {
					ranges[ facet.key ] = range;
				}
			}
			continue;
		}

		if ( facet.type === 'boolean' ) {
			if ( parts.includes( '1' ) ) {
				values[ facet.key ] = [ '1' ];
			}
			continue;
		}

		const list = [
			...new Set(
				parts
					.map( ( v ) => ( facet.taxonomy ? sanitizeTitle( v ) : v ) )
					.filter( ( v ) => v !== '' )
			),
		].sort( byteCompare );
		if ( list.length ) {
			values[ facet.key ] = list;
		}
	}

	const sort = last( raw[ template.sortParam ] || [] );

	return {
		values,
		ranges,
		sort:
			template.sorts[ sort ] && sort !== template.defaultSort ? sort : '',
		page: Math.max( 1, page ),
		// 200 characters, as mb_substr() counts them
		search: Array.from( trim( last( raw[ template.searchParam ] || [] ) ) )
			.slice( 0, 200 )
			.join( '' ),
	};
};

/**
 * The canonical query string of a state, without ?.
 *
 * @param {Object} template
 * @param {Object} state
 * @return {string} The query string.
 */
export const queryString = ( template, state ) => {
	const pairs = [];

	for ( const facet of template.facets ) {
		if ( facet.type === 'range' ) {
			const range = state.ranges[ facet.key ];
			if (
				range &&
				( range.min !== undefined || range.max !== undefined )
			) {
				pairs.push(
					facet.param +
						'=' +
						formatNumber( range.min, facet.decimals ) +
						'..' +
						formatNumber( range.max, facet.decimals )
				);
			}
			continue;
		}

		const values = state.values[ facet.key ] || [];
		if ( ! values.length ) {
			continue;
		}
		if ( facet.type === 'boolean' ) {
			pairs.push( facet.param + '=1' );
			continue;
		}
		pairs.push(
			facet.param +
				'=' +
				values
					.map( ( v ) =>
						rawurlencode(
							// A slug as WordPress stores it: %d0%bf for п
							facet.taxonomy
								? decode( v.replace( /\+/g, '%2B' ) )
								: v
						)
					)
					.join( ',' )
		);
	}

	if ( state.sort && state.sort !== template.defaultSort ) {
		pairs.push( template.sortParam + '=' + rawurlencode( state.sort ) );
	}
	if ( state.search ) {
		pairs.push( template.searchParam + '=' + rawurlencode( state.search ) );
	}

	return pairs.join( '&' );
};

/**
 * The URL of a state on a listing whose first page is base (pretty permalinks).
 *
 * @param {Object} template
 * @param {Object} state
 * @param {string} base
 * @return {string} The URL.
 */
export const url = ( template, state, base ) => {
	let path = base.split( '?' )[ 0 ];
	if ( state.page > 1 ) {
		path = path.replace( /\/?$/, '/' ) + 'page/' + state.page + '/';
	}
	const query = queryString( template, state );
	return query ? path + '?' + query : path;
};
