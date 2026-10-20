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
 * A source's searches: one, or one per weight (Suggest\Render\Plan::searches()).
 *
 * @param {{search: Object, searches?: Array<{weight: number, search: Object}>}} source A source of the plan.
 * @return {Array<{weight: number, search: Object}>} The searches, in the order their answers come back.
 */
const searchesOf = ( source ) =>
	source.searches ?? [ { weight: 1, search: source.search } ];

/**
 * The hits of a source from the answers of its searches: with weights, each
 * hit counts for its best search (ranking score × weight), once.
 *
 * @param {Array<{weight: number}>} searches The source's searches.
 * @param {Object[]}                answers  Their answers, in the same order.
 * @param {number}                  limit    How many hits the source keeps.
 * @return {Object[]} The hits.
 */
export const mergeHits = ( searches, answers, limit ) => {
	if ( searches.length < 2 ) {
		return answers[ 0 ]?.hits || [];
	}

	const best = new Map();
	searches.forEach( ( { weight }, i ) => {
		( answers[ i ]?.hits || [] ).forEach( ( hit ) => {
			const score = weight * Number( hit._rankingScore ?? 0 );
			const key = hit.ID ?? hit.term_id;
			if ( ! best.has( key ) || best.get( key ).score < score ) {
				best.set( key, { hit, score } );
			}
		} );
	} );

	return [ ...best.values() ]
		.sort( ( a, b ) => b.score - a.score )
		.slice( 0, limit )
		.map( ( { hit } ) => hit );
};

/**
 * The searches of a query: one per source, Meilisearch's multi-search body.
 *
 * @param {{sources: Array<{search: Object}>}} plan  Suggest\Render\Plan.
 * @param {string}                             query A normalized query.
 * @return {Object[]} The queries.
 */
export const buildSearches = ( plan, query ) =>
	plan.sources.flatMap( ( source ) =>
		searchesOf( source ).map( ( { search } ) => ( {
			...search,
			q: query,
			highlightPreTag: MARK_OPEN,
			highlightPostTag: MARK_CLOSE,
		} ) )
	);

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
		date: String( doc.post_date ?? '' ),
		metas,
		// The document as Meilisearch returned it: a JavaScript template reads any field of it
		doc,
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

	let cursor = 0;

	plan.sources.forEach( ( source, position ) => {
		const searches = searchesOf( source );
		const answers = results.slice( cursor, cursor + searches.length );
		cursor += searches.length;

		const hits = mergeHits( searches, answers, source.limit ).map(
			( doc ) =>
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
 * HTML a JavaScript template wrote with `html`: its interpolations were escaped.
 */
export class SafeHtml {
	constructor( value ) {
		this.value = value;
	}

	toString() {
		return this.value;
	}
}

/**
 * Escapes text for HTML.
 *
 * @param {*} value
 * @return {string} The text, safe in markup and in a quoted attribute.
 */
export const escape = ( value ) =>
	String( value ?? '' )
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' )
		.replace( /'/g, '&#39;' );

/**
 * The tag of a JavaScript template: html`<a href="${ hit.url }">${ hit.title }</a>`.
 * Interpolations are escaped, unless they are html themselves (or a list of
 * them), so a title never becomes markup.
 *
 * @param {string[]} strings The literal parts.
 * @param {...*}     values
 * @return {SafeHtml} The markup.
 */
export const html = ( strings, ...values ) =>
	new SafeHtml(
		strings.reduce( ( out, text, i ) => {
			const value = values[ i - 1 ];
			let part;
			if ( value instanceof SafeHtml ) {
				part = value.value;
			} else if ( Array.isArray( value ) ) {
				part = value
					.map( ( v ) =>
						v instanceof SafeHtml ? v.value : escape( v )
					)
					.join( '' );
			} else {
				part = escape( value );
			}
			return out + ( i === 0 ? '' : part ) + text;
		}, '' )
	);

/**
 * The element a JavaScript template returned for a suggestion, with what the
 * list box needs: an option with its id, and the active one marked. A template
 * returns `html`, an element, or plain text (a link to the suggestion).
 *
 * @param {Function} template The theme's template: (hit, { html, escape }) => result.
 * @param {Object}   hit      The suggestion.
 * @param {string}   activeId The active option's id.
 * @param {Document} doc      The document to create elements in.
 * @return {Element} The option.
 */
export const optionFrom = ( template, hit, activeId, doc ) => {
	let result;
	try {
		result = template ? template( hit, { html, escape } ) : hit.title;
	} catch ( e ) {
		result = hit.title;
	}

	let node;
	if ( result instanceof SafeHtml ) {
		const holder = doc.createElement( 'template' );
		holder.innerHTML = result.value.trim();
		node = holder.content.firstElementChild;
	} else if ( result && result.nodeType === 1 ) {
		node = result;
	}

	if ( ! node ) {
		node = doc.createElement( 'a' );
		node.textContent = String( result ?? hit.title );
	}

	node.id = hit.optionId;
	node.setAttribute( 'role', 'option' );
	node.setAttribute( 'tabindex', '-1' );
	node.setAttribute( 'aria-selected', String( hit.optionId === activeId ) );
	node.classList.toggle( 'is-active', hit.optionId === activeId );
	if ( node.tagName === 'A' && ! node.getAttribute( 'href' ) ) {
		node.setAttribute( 'href', hit.url );
	}
	node.classList.add( 'meiliscout-suggest__option' );

	return node;
};

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
