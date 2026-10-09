/**
 * The listings' client: the meiliscout/listing store.
 *
 * A change to the filters becomes a state, a URL (codec.js) and two requests
 * at once: the facets' counts, straight to Meilisearch with the listing's
 * tenant token (plan.js), and the results, as an HTML fragment the
 * interactivity router puts in place (navigate(url, { html })). A listing whose
 * client cannot run (no template, another contract version) stays as the
 * server rendered it: its form and links work without JavaScript.
 */
import {
	store,
	getContext,
	getElement,
	withScope,
} from '@wordpress/interactivity';
import { parse, url as urlOf } from './codec';
import { counts, read } from './plan';

const NAMESPACE = 'meiliscout/listing';
const CONTRACT = 1;

/** Requests in flight, by listing: a newer change aborts them. */
const controllers = new Map();

/** Pending search or bound typing, by listing. */
const timers = new Map();

const { state, actions } = store( NAMESPACE, {
	state: {
		get options() {
			const { listing, facet } = getContext();
			return state.listings[ listing ].facets[ facet ].options;
		},
		get optionHidden() {
			const { option } = getContext();
			return option.count === 0 && ! option.selected;
		},
		get rangeMin() {
			const { listing, facet } = getContext();
			return state.listings[ listing ].facets[ facet ].min;
		},
		get rangeMax() {
			const { listing, facet } = getContext();
			return state.listings[ listing ].facets[ facet ].max;
		},
		get rangeMinLimit() {
			const { listing, facet } = getContext();
			const stats = state.listings[ listing ].facets[ facet ].stats;
			return stats ? String( stats.min ) : '';
		},
		get rangeMaxLimit() {
			const { listing, facet } = getContext();
			const stats = state.listings[ listing ].facets[ facet ].stats;
			return stats ? String( stats.max ) : '';
		},
		get totalLabel() {
			const total = state.listings[ getContext().listing ].total;
			const labels = state.i18n.total;
			const label = pluralLabel( labels, total );
			return label.replace( '%d', String( total ) );
		},
		get hasFilters() {
			const listing = state.listings[ getContext().listing ];
			return (
				Object.values( listing.values ).some( ( v ) => v.length ) ||
				Object.keys( listing.ranges ).length > 0 ||
				listing.search !== ''
			);
		},
		get activeFilters() {
			const listing = state.listings[ getContext().listing ];
			const { remove, facetValue } = state.i18n;
			const filters = [];

			for ( const facet of listing.template?.facets || [] ) {
				for ( const value of listing.values[ facet.key ] || [] ) {
					const label = facet.values[ value ]?.label ?? value;
					filters.push( {
						id: facet.key + ':' + value,
						facet: facet.key,
						value,
						label,
						removeLabel: remove.replace( '%s', label ),
					} );
				}
				if ( listing.ranges[ facet.key ] ) {
					const { min, max } = listing.facets[ facet.key ];
					const range = rangeLabel( min, max );
					const label = facetValue
						.replace( '%1$s', facet.label )
						.replace( '%2$s', range );
					filters.push( {
						id: facet.key + ':range',
						facet: facet.key,
						value: '',
						label,
						removeLabel: remove.replace( '%s', label ),
					} );
				}
			}
			if ( listing.search !== '' ) {
				const label = `« ${ listing.search } »`;
				filters.push( {
					id: 'search',
					facet: '',
					value: '',
					label,
					removeLabel: remove.replace( '%s', label ),
				} );
			}
			return filters;
		},
	},

	actions: {
		/**
		 * A checkbox, a select: applied at once (or on the button, apply: button).
		 *
		 * @param {Event} event
		 */
		change( event ) {
			const listing = state.listings[ getContext().listing ];
			if (
				! ready( listing ) ||
				event.target.type === 'search' ||
				event.target.type === 'number'
			) {
				return;
			}
			if ( listing.template.apply === 'instant' ) {
				actions.apply( event.target.form );
			}
		},

		/**
		 * Typing a search or a bound: applied once the typing pauses.
		 *
		 * @param {Event} event
		 */
		input( event ) {
			const { listing: id } = getContext();
			const listing = state.listings[ id ];
			if ( ! ready( listing ) || listing.template.apply !== 'instant' ) {
				return;
			}
			const form = event.target.form;
			clearTimeout( timers.get( id ) );
			timers.set(
				id,
				setTimeout(
					withScope( () => actions.apply( form ) ),
					350
				)
			);
		},

		/**
		 * @param {SubmitEvent} event
		 */
		submit( event ) {
			const listing = state.listings[ getContext().listing ];
			if ( ! ready( listing ) ) {
				return; // The form's own GET
			}
			event.preventDefault();
			actions.apply( event.target );
		},

		/**
		 * The state the form shows, applied.
		 *
		 * @param {HTMLFormElement} form
		 */
		apply( form ) {
			const { listing: id } = getContext();
			const listing = state.listings[ id ];
			const data = new FormData( form );
			const query = new URLSearchParams();
			for ( const [ name, value ] of data ) {
				if ( value !== '' ) {
					query.append( name, value );
				}
			}
			// The form's names and values, read as the server reads a form without JavaScript
			const next = parse(
				listing.template,
				query.toString().replace( /\+/g, '%20' ),
				1
			);
			return actions.update( id, next, 'push' );
		},

		removeFilter() {
			const { listing: id, filter } = getContext();
			const listing = state.listings[ id ];
			const next = current( listing );
			if ( filter.id === 'search' ) {
				next.search = '';
			} else if ( filter.value === '' ) {
				delete next.ranges[ filter.facet ];
			} else {
				next.values[ filter.facet ] = (
					next.values[ filter.facet ] || []
				).filter( ( v ) => v !== filter.value );
			}
			next.page = 1;
			return actions.update( id, next, 'push' );
		},

		/**
		 * @param {MouseEvent} event
		 */
		reset( event ) {
			const { listing: id } = getContext();
			if ( ! ready( state.listings[ id ] ) ) {
				return;
			}
			event.preventDefault();
			return actions.update(
				id,
				{ values: {}, ranges: {}, sort: '', page: 1, search: '' },
				'push'
			);
		},

		/**
		 * A page link: the results of that page, the counts unchanged.
		 *
		 * @param {MouseEvent} event
		 */
		navigate( event ) {
			const { listing: id } = getContext();
			const listing = state.listings[ id ];
			if (
				! ready( listing ) ||
				event.metaKey ||
				event.ctrlKey ||
				event.shiftKey ||
				event.button > 0
			) {
				return;
			}
			event.preventDefault();
			const target = new URL( event.target.closest( 'a' ).href );
			const page = Number(
				( target.pathname.match( /\/page\/(\d+)\/?$/ ) || [] )[ 1 ] || 1
			);
			return actions.update(
				id,
				{ ...current( listing ), page },
				'push',
				{ counts: false }
			);
		},

		/**
		 * Moves a listing to a state: its counts, its results, its URL.
		 *
		 * @param {string}  id
		 * @param {Object}  next
		 * @param {string}  history        push, or replace
		 * @param {Object}  options
		 * @param {boolean} options.counts Whether the counts change.
		 */
		*update( id, next, history, { counts: recount = true } = {} ) {
			const listing = state.listings[ id ];
			const target = urlOf( listing.template, next, listing.base );

			if ( listing.transport === 'page' ) {
				window.location.assign( target );
				return;
			}

			controllers.get( id )?.abort();
			const controller = new AbortController();
			controllers.set( id, controller );
			listing.busy = true;

			try {
				const [ counted, html ] = yield Promise.all( [
					recount
						? countFacets( listing, next, controller.signal )
						: null,
					fragment( listing, target, controller.signal ),
				] );

				if ( controller.signal.aborted ) {
					return;
				}

				const { actions: router } = yield import(
					'@wordpress/interactivity-router'
				);
				yield router.navigate( target, {
					html: withStyles( html ),
					replace: history === 'replace',
				} );

				Object.assign( listing, {
					values: next.values,
					ranges: next.ranges,
					sort: next.sort || listing.template.defaultSort,
					search: next.search,
					page: next.page,
				} );
				for ( const facet of listing.template.facets ) {
					if ( facet.type === 'range' ) {
						const range = next.ranges[ facet.key ] || {};
						listing.facets[ facet.key ].min =
							range.min !== undefined ? String( range.min ) : '';
						listing.facets[ facet.key ].max =
							range.max !== undefined ? String( range.max ) : '';
					}
				}
				if ( counted ) {
					applyCounts( listing, next, counted );
				}
			} catch ( error ) {
				if ( error?.name !== 'AbortError' ) {
					// Anything wrong: the page the server renders for this state
					window.location.assign( target );
				}
			} finally {
				if ( controllers.get( id ) === controller ) {
					listing.busy = false;
				}
			}
		},
	},

	callbacks: {
		/**
		 * A listing whose template the client cannot read stays as the server rendered it.
		 */
		init() {
			const { listing: id } = getContext();
			const listing = state.listings[ id ];
			if ( ! listing.template || listing.template.version !== CONTRACT ) {
				listing.transport = 'page';
				return;
			}

			// Back and forward: the router puts the results back, the counts follow
			window.addEventListener(
				'popstate',
				withScope( () => {
					const query = window.location.search.replace( /^\?/, '' );
					const page = Number(
						( window.location.pathname.match(
							/\/page\/(\d+)\/?$/
						) || [] )[ 1 ] || 1
					);
					const next = parse( listing.template, query, page );
					countFacets( listing, next ).then(
						( counted ) => {
							Object.assign( listing, {
								values: next.values,
								ranges: next.ranges,
								sort: next.sort || listing.template.defaultSort,
								search: next.search,
								page,
							} );
							applyCounts( listing, next, counted );
						},
						() => {}
					);
				} )
			);
		},

		/**
		 * The apply button only shows where it is needed: without JavaScript, or apply: button.
		 */
		applyButton() {
			const listing = state.listings[ getContext().listing ];
			if ( ready( listing ) && listing.template.apply === 'instant' ) {
				getElement().ref.hidden = true;
			}
		},
	},
} );

/**
 * The total's label in this language's plural forms for 0, 1 and more.
 *
 * @param {Object} labels
 * @param {number} total
 * @return {string} The label template.
 */
function pluralLabel( labels, total ) {
	if ( total === 0 ) {
		return labels.zero;
	}
	return total === 1 ? labels.one : labels.many;
}

/**
 * 1000 – 5000, ≥ 1000, ≤ 5000 (Store::rangeLabel()).
 *
 * @param {string} min
 * @param {string} max
 * @return {string} The label.
 */
function rangeLabel( min, max ) {
	if ( min !== '' && max !== '' ) {
		return `${ min } – ${ max }`;
	}
	return min !== '' ? `≥ ${ min }` : `≤ ${ max }`;
}

/**
 * @param {Object} listing
 * @return {boolean} Whether the client can move this listing.
 */
function ready( listing ) {
	return Boolean( listing.template ) && listing.transport !== 'page';
}

/**
 * A copy of a listing's state.
 *
 * @param {Object} listing
 * @return {Object} The state.
 */
function current( listing ) {
	return {
		values: Object.fromEntries(
			Object.entries( listing.values ).map( ( [ k, v ] ) => [
				k,
				[ ...v ],
			] )
		),
		ranges: Object.fromEntries(
			Object.entries( listing.ranges ).map( ( [ k, v ] ) => [
				k,
				{ ...v },
			] )
		),
		sort: listing.sort === listing.template.defaultSort ? '' : listing.sort,
		page: listing.page,
		search: listing.search,
	};
}

/**
 * The facets' counts in a state, from Meilisearch with the listing's token;
 * a new token once when it expired or was refused.
 *
 * @param {Object}      listing
 * @param {Object}      next
 * @param {AbortSignal} signal
 * @return {Promise<Object>} The counts (plan.js read()).
 */
async function countFacets( listing, next, signal ) {
	const searches = counts( listing.template, next );
	const ask = () =>
		fetch( `${ listing.host }/multi-search`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				Authorization: `Bearer ${ listing.token.value }`,
			},
			body: JSON.stringify( { queries: searches } ),
			signal,
		} );

	if ( ! listing.token || listing.token.exp * 1000 < Date.now() + 60000 ) {
		listing.token = await refreshToken( listing, signal );
	}

	let response = await ask();
	if ( response.status === 401 || response.status === 403 ) {
		listing.token = await refreshToken( listing, signal );
		response = await ask();
	}
	if ( ! response.ok ) {
		throw new Error( `Meilisearch answered ${ response.status }` );
	}

	return read( searches, ( await response.json() ).results );
}

async function refreshToken( listing, signal ) {
	const response = await fetch( listing.endpoints.token, {
		signal,
		credentials: 'omit',
	} );
	if ( ! response.ok ) {
		throw new Error( 'No token' );
	}
	return response.json();
}

/**
 * The HTML of the results at a URL.
 *
 * @param {Object}      listing
 * @param {string}      target
 * @param {AbortSignal} signal
 * @return {Promise<string>} A document holding the listing's router region.
 */
async function fragment( listing, target, signal ) {
	const { pathname, search } = new URL( target, window.location.href );
	const endpoint = new URL( listing.endpoints.fragment );
	endpoint.searchParams.set( 'url', pathname + search );
	const response = await fetch( endpoint, {
		signal,
		credentials: listing.personalised ? 'same-origin' : 'omit',
	} );
	if ( ! response.ok ) {
		throw new Error( `The fragment answered ${ response.status }` );
	}
	return response.text();
}

/**
 * The router disables every stylesheet absent from the HTML it gets: the
 * page's own go along with the fragment (prototype P1).
 *
 * @param {string} html
 * @return {string} The fragment, with the page's stylesheets.
 */
function withStyles( html ) {
	const styles = [
		...document.querySelectorAll( 'style,link[rel=stylesheet]' ),
	]
		.map( ( el ) => el.outerHTML )
		.join( '' );
	return html.replace( '</head>', `${ styles }</head>` );
}

/**
 * Puts counts in a listing's state: each facet's options, in the template's
 * order, the bounds and the total.
 *
 * @param {Object} listing
 * @param {Object} next
 * @param {Object} counted
 */
function applyCounts( listing, next, counted ) {
	for ( const facet of listing.template.facets ) {
		const entry = listing.facets[ facet.key ];
		entry.stats = counted.stats[ facet.field ] || null;

		if ( facet.type === 'range' ) {
			continue;
		}
		const selected = next.values[ facet.key ] || [];
		const distribution = counted.distributions[ facet.field ] || {};
		entry.options = Object.entries( facet.values ).map(
			( [ value, known ] ) => ( {
				value,
				label: known.label,
				count: distribution[ known.id ] ?? 0,
				selected: selected.includes( value ),
				depth: known.depth,
			} )
		);
	}
	listing.total = counted.total;
	listing.pages = Math.ceil( counted.total / listing.template.perPage );
}

export { state, actions };
