/**
 * The searches counting a listing's facets: the client's half of
 * Listings\Query\FacetPlan::counts(), from the template's clauses alone
 * (tests/fixtures/listings/plan-cases.json). No base filter: the tenant
 * token adds it.
 */
import { formatNumber } from './codec';

const isDisjunctive = ( facet ) =>
	facet.type === 'range' || ( facet.type === 'list' && facet.logic === 'or' );

const compose = ( facet, clauses ) => {
	if ( clauses.length === 1 ) {
		return clauses[ 0 ];
	}
	const glue =
		facet.logic === 'or' && facet.type === 'list' ? ' OR ' : ' AND ';
	return '(' + clauses.join( glue ) + ')';
};

/**
 * A facet's filter in a state, null without a selection.
 *
 * @param {Object} facet A template facet.
 * @param {Object} state
 * @return {string|null} The filter.
 */
export const clause = ( facet, state ) => {
	if ( facet.type === 'range' ) {
		const range = state.ranges[ facet.key ];
		if ( ! range ) {
			return null;
		}
		const parts = [ 'min', 'max' ]
			.filter(
				( bound ) =>
					range[ bound ] !== undefined && range[ bound ] !== null
			)
			.map( ( bound ) =>
				facet.bounds[ bound ].replace(
					'{n}',
					formatNumber( range[ bound ], facet.decimals )
				)
			);
		if ( ! parts.length ) {
			return null;
		}
		return parts.length === 1
			? parts[ 0 ]
			: '(' + parts.join( ' AND ' ) + ')';
	}

	const values = state.values[ facet.key ] || [];
	// A value the template does not know has no filter it could send
	const clauses = values
		.map( ( value ) => facet.values[ value ]?.clause )
		.filter( Boolean );
	return clauses.length ? compose( facet, clauses ) : null;
};

const search = ( template, clauses, without, state ) => {
	const filters = Object.keys( clauses )
		.filter( ( key ) => key !== without )
		.map( ( key ) => clauses[ key ] );
	const query = { indexUid: template.index };
	if ( state.search ) {
		query.q = state.search;
	}
	if ( filters.length ) {
		query.filter = filters.join( ' AND ' );
	}
	return query;
};

/**
 * @param {Object} template
 * @param {Object} state
 * @return {Object<string, string>} Each active facet's filter, in the template's order.
 */
const clauses = ( template, state ) => {
	const all = {};
	for ( const facet of template.facets ) {
		const c = clause( facet, state );
		if ( c !== null ) {
			all[ facet.key ] = c;
		}
	}
	return all;
};

/**
 * The searches counting the facets in a state.
 *
 * @param {Object} template
 * @param {Object} state
 * @return {Object[]} The multi-search queries.
 */
export const counts = ( template, state ) => {
	const active = clauses( template, state );
	const facets = [];
	const searches = [];

	for ( const facet of template.facets ) {
		if ( ! ( facet.key in active ) || ! isDisjunctive( facet ) ) {
			facets.push( facet.field );
			continue;
		}
		searches.push( {
			...search( template, active, facet.key, state ),
			facets: [ facet.field ],
			limit: 0,
		} );
	}

	return [
		{ ...search( template, active, null, state ), facets, limit: 0 },
		...searches,
	];
};

/**
 * Each field's counts and bounds, from the search that carries it.
 *
 * @param {Object[]} searches The searches sent.
 * @param {Object[]} results  Meilisearch's answers.
 * @return {{distributions: Object, stats: Object, total: number}} The counts.
 */
export const read = ( searches, results ) => {
	const distributions = {};
	const stats = {};
	searches.forEach( ( query, i ) => {
		for ( const field of query.facets ) {
			distributions[ field ] =
				results[ i ]?.facetDistribution?.[ field ] || {};
			stats[ field ] = results[ i ]?.facetStats?.[ field ] || null;
		}
	} );
	return {
		distributions,
		stats,
		total: results[ 0 ]?.estimatedTotalHits ?? results[ 0 ]?.totalHits ?? 0,
	};
};

/**
 * The search of a page of results, for the client transport:
 * FacetPlan::results().
 *
 * @param {Object} template
 * @param {Object} state
 * @return {Object} The search.
 */
export const results = ( template, state ) => ( {
	...search( template, clauses( template, state ), null, state ),
	sort: template.sorts[ state.sort || template.defaultSort ] || [],
	limit: template.perPage,
	offset: ( state.page - 1 ) * template.perPage,
	attributesToRetrieve: template.fields,
	attributesToCrop: [ 'content_text' ],
	cropLength: template.excerptLength,
} );

/**
 * Marks the values shown past a facet's limit: ListingQuery::withOverflow().
 * A selected value is never folded.
 *
 * @param {Object[]} options
 * @param {number}   limit   0: no limit.
 * @return {Object[]} The options.
 */
export function withOverflow( options, limit ) {
	let shown = 0;
	return options.map( ( option ) => {
		const visible = option.count > 0 || option.selected;
		const overflow =
			limit > 0 && visible && ! option.selected && shown >= limit;
		if ( visible ) {
			shown++;
		}
		return { ...option, overflow };
	} );
}
