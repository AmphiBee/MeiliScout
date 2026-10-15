/**
 * The suggest fields' client logic, free of the DOM and of the store: what to
 * ask Meilisearch (the plan PHP wrote, replayed as is), how to read its
 * answer into suggestions a template binds, and the small memory that keeps
 * the last answers. view.js wires it to the page.
 *
 * What a suggestion holds is the contract of Suggest\Render\Templates: keep
 * both alike.
 */
import { decodeEntities } from '../listings/hits';

/** Private-use characters Meilisearch wraps a match in: never in a title. */
export const MARK_OPEN = '';
export const MARK_CLOSE = '';

/**
 * What the visitor typed, as one search: trimmed, spaces collapsed.
 *
 * @param {string} query
 * @return {string} The query.
 */
export const normalize = ( query ) =>
	String( query ?? '' )
		.replace( /\s+/g, ' ' )
		.trim();

/**
 * The searches of a query: one per source, Meilisearch's multi-search body.
 *
 * @param {{sources: Array<{search: Object}>}} plan  Suggest\Render\Plan.
 * @param {string}                             query A normalized query.
 * @return {Object[]} The queries.
 */
export const buildSearches = ( plan, query ) =>
	plan.sources.map( ( source ) => ( {
		...source.search,
		q: query,
		highlightPreTag: MARK_OPEN,
		highlightPostTag: MARK_CLOSE,
	} ) );

/**
 * A highlighted title, cut in the parts a template binds: text, and whether it
 * matched. Entities are decoded after the cut and every part is bound as
 * text, so a title never becomes markup.
 *
 * @param {string} formatted A title with its matches between MARK_OPEN and MARK_CLOSE.
 * @return {Array<{key: number, text: string, match: boolean}>} The parts.
 */
export const titleParts = ( formatted ) => {
	const parts = [];
	let match = false;

	for ( const piece of String( formatted ?? '' ).split(
		new RegExp( `([${ MARK_OPEN }${ MARK_CLOSE }])` )
	) ) {
		if ( piece === MARK_OPEN || piece === MARK_CLOSE ) {
			match = piece === MARK_OPEN;
			continue;
		}
		const text = decodeEntities( piece );
		if ( text !== '' ) {
			parts.push( { key: parts.length, text, match } );
		}
	}

	return parts;
};

/**
 * As the text of a meta value.
 *
 * @param {*} value
 * @return {string} The text.
 */
const scalar = ( value ) => {
	if ( typeof value === 'boolean' ) {
		return value ? '1' : '';
	}
	return typeof value === 'number' || typeof value === 'string'
		? String( value )
		: '';
};

/**
 * A suggestion from a document: Meilisearch's hit of a source.
 *
 * @param {{type: string}}         source The plan's source.
 * @param {Object}                 doc    A hit, with its _formatted title.
 * @param {Object<string, string>} labels The label of each post type and taxonomy.
 * @param {string}                 prefix The field's DOM id and the source's position: ids stay unique.
 * @return {Object} The suggestion.
 */
export const suggestionFromDocument = ( source, doc, labels, prefix ) => {
	const isPosts = source.type === 'posts';
	const raw = isPosts ? doc.post_title : doc.name;
	const formatted = isPosts
		? doc._formatted?.post_title
		: doc._formatted?.name;
	const parts = titleParts( formatted ?? raw );
	const id = isPosts ? doc.ID : doc.term_id;
	const kind = String( isPosts ? doc.post_type : doc.taxonomy );

	const metas = {};
	for ( const [ key, value ] of Object.entries( doc.metas || {} ) ) {
		metas[ key ] = Array.isArray( value )
			? value.map( scalar )
			: scalar( value );
	}

	return {
		optionId: `${ prefix }-${ id }`,
		id: Number( id ?? 0 ),
		url: String( doc.url ?? '' ),
		title: decodeEntities( raw ),
		titleParts: parts,
		type: kind,
		meta: labels[ kind ] ?? kind,
		image: String( doc.thumbnail?.url ?? '' ),
		imageAlt: String( doc.thumbnail?.alt ?? '' ),
		count: Number( doc.count ?? 0 ),
		excerpt: decodeEntities( doc.post_excerpt ),
		metas,
	};
};

/**
 * Meilisearch's answers as the field shows them: a group per source, the
 * options in the order the keyboard walks them, and the total.
 *
 * @param {Object}                 plan    Suggest\Render\Plan.
 * @param {Object[]}               results The multi-search's results, in the plan's order.
 * @param {Object<string, string>} labels  Type labels.
 * @param {string}                 dom     The field's DOM id.
 * @return {{groups: Object[], options: Object[], total: number}} What to show.
 */
export const readAnswers = ( plan, results, labels, dom ) => {
	const groups = [];
	const options = [];

	plan.sources.forEach( ( source, position ) => {
		const hits = ( results[ position ]?.hits || [] ).map( ( doc ) =>
			suggestionFromDocument(
				source,
				doc,
				labels,
				`${ dom }-${ position }`
			)
		);
		hits.forEach( ( hit ) =>
			options.push( { id: hit.optionId, url: hit.url } )
		);
		groups.push( { key: source.key, hits, hasHits: hits.length > 0 } );
	} );

	return { groups, options, total: options.length };
};

/**
 * An answer with at most so many suggestions per group: the phone shows
 * fewer. The options follow, in the same order.
 *
 * @param {{groups: Object[], options: Object[], total: number}} answer
 * @param {number}                                               perGroup How many each group keeps.
 * @return {{groups: Object[], options: Object[], total: number}} The shorter answer.
 */
export const limitAnswer = ( answer, perGroup ) => {
	const groups = answer.groups.map( ( group ) => {
		const hits = group.hits.slice( 0, perGroup );
		return { ...group, hits, hasHits: hits.length > 0 };
	} );
	const options = groups.flatMap( ( group ) =>
		group.hits.map( ( hit ) => ( { id: hit.optionId, url: hit.url } ) )
	);

	return { groups, options, total: options.length };
};

/**
 * Recent searches as suggestions the field's list shows and the keyboard walks.
 *
 * @param {string[]} queries The recent searches.
 * @param {string}   dom     The field's DOM id.
 * @param {Function} urlOf   A query's results page.
 * @return {Object[]} The recent suggestions.
 */
export const recentSuggestions = ( queries, dom, urlOf ) =>
	queries.map( ( query, i ) => ( {
		optionId: `${ dom }-r-${ i }`,
		title: query,
		query,
		url: urlOf( query ),
	} ) );

/**
 * Where "all results" leads: the results page with the query.
 *
 * @param {string} action The results page.
 * @param {string} param  The query string parameter.
 * @param {string} query  The query.
 * @return {string} The address.
 */
export const allUrl = ( action, param, query ) => {
	try {
		const url = new URL( action, 'http://x.invalid' );
		url.searchParams.set( param, query );
		return action.startsWith( 'http' )
			? url.toString()
			: url.pathname + url.search + url.hash;
	} catch ( e ) {
		return action;
	}
};

/**
 * The live region's sentence: the number of suggestions, in the language's plural form.
 *
 * @param {{zero: string, one: string, many: string}} forms Plural forms with %d.
 * @param {number}                                    total
 * @return {string} The sentence.
 */
export const announce = ( forms, total ) => {
	let form = forms.many;
	if ( total === 0 ) {
		form = forms.zero;
	} else if ( total === 1 ) {
		form = forms.one;
	}
	return form.replace( '%d', String( total ) );
};

/**
 * The option the arrows reach: from the current one, one step, round the list.
 * Nothing (-1) is a step before the first and after the last, as the input
 * itself: the arrow back to the field.
 *
 * @param {number} current Index of the active option, -1 for none.
 * @param {number} by      1 or -1.
 * @param {number} length  Number of options.
 * @return {number} The new index.
 */
export const step = ( current, by, length ) => {
	if ( length === 0 ) {
		return -1;
	}
	const next = current + by;
	if ( next >= length ) {
		return -1;
	}
	if ( next < -1 ) {
		return length - 1;
	}
	return next;
};

/**
 * The last answers, by query: a retyped or deleted letter costs nothing.
 */
export class Memory {
	/**
	 * @param {number} size How many answers to keep.
	 */
	constructor( size = 20 ) {
		this.size = size;
		this.entries = new Map();
	}

	get( key ) {
		if ( ! this.entries.has( key ) ) {
			return undefined;
		}
		const value = this.entries.get( key );
		// Most recent last
		this.entries.delete( key );
		this.entries.set( key, value );
		return value;
	}

	set( key, value ) {
		this.entries.delete( key );
		this.entries.set( key, value );
		if ( this.entries.size > this.size ) {
			this.entries.delete( this.entries.keys().next().value );
		}
	}
}
