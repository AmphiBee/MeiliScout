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

const encoder = new TextEncoder();

/**
 * Byte order, as PHP's sort( SORT_STRING ).
 *
 * @param {string} a
 * @param {string} b
 * @return {number} The order.
 */
export const byteCompare = ( a, b ) => {
	const ea = encoder.encode( a );
	const eb = encoder.encode( b );
	for ( let i = 0; i < Math.min( ea.length, eb.length ); i++ ) {
		if ( ea[ i ] !== eb[ i ] ) {
			return ea[ i ] - eb[ i ];
		}
	}
	return ea.length - eb.length;
};

/**
 * A bound as UrlCodec::number() writes it: 1500, 1500.5.
 *
 * @param {number|null|undefined} value
 * @param {number}                decimals
 * @return {string} The bound.
 */
export const formatNumber = ( value, decimals ) =>
	value === null || value === undefined || Number.isNaN( value )
		? ''
		: String( Number( Number( value ).toFixed( decimals ) ) );

const decode = ( part ) => {
	try {
		return decodeURIComponent( part.replace( /\+/g, ' ' ) );
	} catch {
		return part;
	}
};

/**
 * WordPress's sanitize_title() for the slugs the client may meet: lowercase,
 * and non-ASCII characters percent-encoded in lowercase.
 *
 * @param {string} value
 * @return {string} The slug.
 */
const slug = ( value ) =>
	value
		.toLowerCase()
		.replace( /[^\x00-\x7F]/gu, ( c ) =>
			encodeURIComponent( c ).toLowerCase()
		);

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
					range.min = Number(
						Number( m[ 1 ] ).toFixed( facet.decimals )
					);
				}
				if ( m[ 2 ] !== undefined ) {
					range.max = Number(
						Number( m[ 2 ] ).toFixed( facet.decimals )
					);
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
					.map( ( v ) => ( facet.taxonomy ? slug( v ) : v ) )
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
		search: last( raw[ template.searchParam ] || [] )
			.trim()
			.slice( 0, 200 ),
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
							facet.taxonomy ? decodeURIComponent( v ) : v
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
