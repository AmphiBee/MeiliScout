/**
 * The suggest fields' client: the meiliscout/suggest store.
 *
 * What the visitor types becomes one multi-search, straight to Meilisearch
 * with the field's tenant token (WordPress is not involved), and its answers
 * become suggestions the field's templates bind (client.js). A field whose
 * client cannot run (no token, another contract version) stays the plain
 * search form it is: it submits to the results page.
 *
 * Quick on purpose: a short debounce, the previous request aborted, the
 * previous suggestions kept on screen until the new ones come (no flicker),
 * the last answers remembered, the connection opened when the field takes
 * the focus.
 */
import { store, getContext, getElement } from '@wordpress/interactivity';
import {
	Memory,
	allUrl,
	announce,
	buildSearches,
	normalize,
	readAnswers,
	step,
} from './client';

const NAMESPACE = 'meiliscout/suggest';

/** The plan's contract this client reads (Suggest\Render\Plan::VERSION). */
const CONTRACT = 1;

/** What lives apart from the reactive context, by field: request, timer, memory. */
const runtime = new Map();

const { state } = store( NAMESPACE, {
	state: {
		get selected() {
			const { hit, activeId } = getContext();
			return hit.optionId === activeId ? 'true' : 'false';
		},
		get active() {
			const { hit, activeId } = getContext();
			return hit.optionId === activeId;
		},
	},

	actions: {
		input( event ) {
			const context = getContext();
			context.query = event.target.value;
			schedule( context );
		},

		focus() {
			const context = getContext();
			context.focused = true;
			warmUp( context );
			// Back in the field: the suggestions of what it holds, if any
			if (
				usable( context ) &&
				normalize( context.query ).length >= context.minChars
			) {
				if (
					context.groups.some( ( group ) => group.hasHits ) ||
					context.empty
				) {
					context.open = true;
				} else {
					schedule( context, 0 );
				}
			}
		},

		keydown( event ) {
			const context = getContext();

			if ( ! usable( context ) ) {
				return;
			}

			switch ( event.key ) {
				case 'ArrowDown':
				case 'ArrowUp':
					event.preventDefault();
					if ( ! context.open ) {
						if ( context.options.length ) {
							context.open = true;
						}
						return;
					}
					move( context, event.key === 'ArrowDown' ? 1 : -1 );
					break;
				case 'Enter':
					// An active suggestion is followed; else the form submits
					if ( context.activeId ) {
						const option = context.options.find(
							( o ) => o.id === context.activeId
						);
						if ( option?.url ) {
							event.preventDefault();
							window.location.assign( option.url );
						}
					}
					break;
				case 'Escape':
					if ( context.open ) {
						event.preventDefault();
						close( context );
					}
					break;
				default:
			}
		},

		submit() {
			// The form submits as any search: nothing to do but not to keep a request going
			const context = getContext();
			cancel( context );
			close( context );
		},

		focusOut( event ) {
			const context = getContext();
			const { ref } = getElement();
			context.focused = ref.contains( event.relatedTarget );
			if ( ! context.focused ) {
				close( context );
			}
		},

		clickOutside( event ) {
			const context = getContext();
			const { ref } = getElement();
			if ( context.open && ! ref.contains( event.target ) ) {
				close( context );
			}
		},

		/**
		 * A press in the panel must not take the focus from the field.
		 * @param {Event} event
		 */
		keepFocus( event ) {
			event.preventDefault();
		},
	},

	callbacks: {
		init() {
			const context = getContext();
			context.focused = false;
			runtime.set( context.id, {
				memory: new Memory(),
				controller: null,
				timer: 0,
				warmed: false,
			} );
		},
	},
} );

/**
 * Whether this client runs the field: it has a token and reads the plan.
 *
 * @param {Object} context
 * @return {boolean} Whether it does.
 */
function usable( context ) {
	return Boolean(
		context.ready && context.token && context.plan.version === CONTRACT
	);
}

/**
 * Opens the connection to Meilisearch before the first keystroke.
 *
 * @param {Object} context
 */
function warmUp( context ) {
	const run = runtime.get( context.id );
	if ( ! run || run.warmed || ! usable( context ) ) {
		return;
	}
	run.warmed = true;
	const link = document.createElement( 'link' );
	link.rel = 'preconnect';
	link.href = context.host;
	link.crossOrigin = 'anonymous';
	document.head.appendChild( link );
}

/**
 * Searches after the debounce; the first letter past the minimum goes at once.
 *
 * @param {Object} context
 * @param {number} [delay] Milliseconds, the field's debounce by default.
 */
function schedule( context, delay ) {
	const run = runtime.get( context.id );
	if ( ! run || ! usable( context ) ) {
		return;
	}

	clearTimeout( run.timer );
	const query = normalize( context.query );

	if ( query.length < context.minChars ) {
		cancel( context );
		close( context );
		reset( context );
		return;
	}

	const wait = delay ?? ( run.memory.get( query ) ? 0 : context.debounce );
	run.timer = setTimeout( () => search( context, query ), wait );
}

/**
 * Asks, or recalls, the suggestions of a query and shows them.
 *
 * @param {Object} context
 * @param {string} query
 */
async function search( context, query ) {
	const run = runtime.get( context.id );
	const remembered = run.memory.get( query );

	if ( remembered ) {
		show( context, query, remembered );
		return;
	}

	cancel( context );
	run.controller = new AbortController();
	const { signal } = run.controller;
	context.busy = true;

	try {
		const results = await multiSearch(
			context,
			buildSearches( context.plan, query ),
			signal
		);
		const answer = readAnswers(
			context.plan,
			results,
			context.labels,
			context.id
		);
		run.memory.set( query, answer );
		// What was typed since is another query: its own answer is coming
		if ( query === normalize( context.query ) ) {
			show( context, query, answer );
		}
	} catch ( error ) {
		if ( error.name === 'AbortError' ) {
			return;
		}
		// Not a dead end: the form submits as a plain search
		context.announce = state.i18n.error;
		close( context );
	} finally {
		if ( ! signal.aborted ) {
			context.busy = false;
		}
	}
}

/**
 * Puts an answer on screen, when the visitor is still in the field.
 *
 * @param {Object} context
 * @param {string} query
 * @param {Object} answer
 */
function show( context, query, answer ) {
	context.groups = answer.groups;
	context.options = answer.options;
	context.total = answer.total;
	context.empty = answer.total === 0;
	context.emptyText =
		answer.total === 0 ? state.i18n.empty.replace( '%s', query ) : '';
	context.canSeeAll = answer.total > 0;
	context.allUrl = allUrl( context.action, context.param, query );
	context.activeId = '';
	context.announce =
		answer.total === 0
			? context.emptyText
			: announce( state.i18n.results, answer.total );
	context.open = context.focused;
}

/**
 * The suggestions' state of a query too short to search.
 *
 * @param {Object} context
 */
function reset( context ) {
	context.groups = context.groups.map( ( group ) => ( {
		key: group.key,
		hits: [],
		hasHits: false,
	} ) );
	context.options = [];
	context.total = 0;
	context.empty = false;
	context.emptyText = '';
	context.canSeeAll = false;
	context.activeId = '';
	context.announce = '';
}

/**
 * Moves the active suggestion, scrolling it into view.
 *
 * @param {Object} context
 * @param {number} by      1 or -1.
 */
function move( context, by ) {
	const current = context.options.findIndex(
		( o ) => o.id === context.activeId
	);
	const next = step( current, by, context.options.length );
	context.activeId = next === -1 ? '' : context.options[ next ].id;

	if ( context.activeId ) {
		document
			.getElementById( context.activeId )
			?.scrollIntoView( { block: 'nearest' } );
	}
}

function close( context ) {
	context.open = false;
	context.activeId = '';
}

function cancel( context ) {
	const run = runtime.get( context.id );
	run?.controller?.abort();
	context.busy = false;
}

/**
 * Searches Meilisearch with the field's token; a new token once when it
 * expired or was refused.
 *
 * @param {Object}      context
 * @param {Object[]}    queries
 * @param {AbortSignal} signal
 * @return {Promise<Object[]>} Meilisearch's results.
 */
async function multiSearch( context, queries, signal ) {
	const ask = () =>
		fetch( `${ context.host }/multi-search`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				Authorization: `Bearer ${ context.token.value }`,
			},
			body: JSON.stringify( { queries } ),
			signal,
		} );

	if ( context.token.exp * 1000 < Date.now() + 60000 ) {
		await refreshToken( context, signal );
	}

	let response = await ask();
	if ( response.status === 401 || response.status === 403 ) {
		await refreshToken( context, signal );
		response = await ask();
	}
	if ( ! response.ok ) {
		throw new Error( `Meilisearch answered ${ response.status }` );
	}

	return ( await response.json() ).results;
}

async function refreshToken( context, signal ) {
	const response = await fetch( context.endpoint, {
		signal,
		credentials: 'omit',
	} );
	if ( ! response.ok ) {
		throw new Error( 'No token' );
	}
	const { value, exp, host } = await response.json();
	context.token = { value, exp };
	context.host = host || context.host;
}
