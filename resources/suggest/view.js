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
	limitAnswer,
	normalize,
	optionFrom,
	readAnswers,
	recentSuggestions,
	step,
} from './client';
import { add, browserStorage, clear, load } from './recent';

const NAMESPACE = 'meiliscout/suggest';

/** The listings' breakpoint: below it, the field opens as a full-screen sheet. */
const MOBILE = '(max-width: 781.98px)';

/** On the page's root while a sheet is open: the page behind does not scroll. */
const LOCKED = 'meiliscout-suggest-locked';

const storage = browserStorage();

/** The plan's contract this client reads (Suggest\Render\Plan::VERSION). */
const CONTRACT = 1;

/**
 * What lives apart from the reactive context, by field: its request, timer,
 * memory, and the sheet's history entry and viewport listener.
 */
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
			if ( ! usable( context ) ) {
				return;
			}
			warmUp( context );

			// On a phone the field opens as a sheet, of its own accord
			if ( ! context.sheet && window.matchMedia( MOBILE ).matches ) {
				openSheet( context );
			}

			if ( normalize( context.query ).length < context.minChars ) {
				showRecent( context );
			} else if (
				context.groups.some( ( group ) => group.hasHits ) ||
				context.empty
			) {
				// Back in the field: the suggestions of what it holds
				context.open = true;
			} else {
				schedule( context, 0 );
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
						} else {
							showRecent( context );
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
							remember( context, option.query ?? context.query );
							window.location.assign( option.url );
						}
					}
					break;
				case 'Escape':
					if ( context.sheet ) {
						event.preventDefault();
						closeSheet( context );
					} else if ( context.open ) {
						event.preventDefault();
						close( context );
					}
					break;
				default:
			}
		},

		submit() {
			// The form submits as any search: remember it, and stop asking
			const context = getContext();
			remember( context, context.query );
			cancel( context );
			close( context );
		},

		focusOut( event ) {
			const context = getContext();
			const { ref } = getElement();
			context.focused = ref.contains( event.relatedTarget );
			// A sheet stays open: scrolling its list takes the keyboard away, not the sheet
			if ( ! context.focused && ! context.sheet ) {
				close( context );
			}
		},

		clickOutside( event ) {
			const context = getContext();
			const { ref } = getElement();
			if (
				context.open &&
				! context.sheet &&
				! ref.contains( event.target )
			) {
				close( context );
			}
		},

		/**
		 * A press in the panel must not take the focus from the field.
		 *
		 * @param {Event} event
		 */
		keepFocus( event ) {
			event.preventDefault();
		},

		/**
		 * A suggestion followed: its search is remembered.
		 *
		 * @param {Event} event
		 */
		panelClick( event ) {
			const context = getContext();
			const link = event.target.closest?.( 'a[role="option"]' );
			if ( link ) {
				remember(
					context,
					link.classList.contains(
						'meiliscout-suggest__option--recent'
					)
						? link.textContent
						: context.query
				);
			}
		},

		/** Scrolling the sheet's list puts the keyboard away. */
		touchScroll() {
			const context = getContext();
			const input = document.getElementById( `${ context.id }-input` );
			if (
				context.sheet &&
				input.ownerDocument.activeElement === input
			) {
				input.blur();
			}
		},

		closeSheet() {
			closeSheet( getContext() );
		},

		forget() {
			const context = getContext();
			clear( storage, context.field );
			context.recent = [];
			context.options = [];
			context.activeId = '';
			context.open = context.sheet;
		},
	},

	callbacks: {
		/**
		 * Draws a group's suggestions with the theme's JavaScript template
		 * (`js:<name>`: window.MeiliScout.suggestTemplates[name]), again
		 * whenever its suggestions or the active one change.
		 */
		renderGroup() {
			const context = getContext();
			const { ref } = getElement();
			const group = context.groups[ Number( ref.dataset.group ) ];
			const template =
				window.MeiliScout?.suggestTemplates?.[ ref.dataset.template ];

			ref.replaceChildren(
				...group.hits.map( ( hit ) =>
					optionFrom(
						template,
						hit,
						context.activeId,
						ref.ownerDocument
					)
				)
			);
		},

		init() {
			const context = getContext();
			context.focused = false;
			runtime.set( context.id, {
				context,
				memory: new Memory(),
				controller: null,
				timer: 0,
				warmed: false,
				pushed: false,
				ignorePop: false,
				viewport: null,
			} );
		},
	},
} );

/**
 * The back button closes a sheet, as it closes a page's panel.
 */
window.addEventListener( 'popstate', () => {
	for ( const run of runtime.values() ) {
		if ( run.ignorePop ) {
			run.ignorePop = false;
		} else if ( run.context.sheet ) {
			closeSheet( run.context, true );
		}
	}
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
	if ( ! run || run.warmed ) {
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
 * Searches after the debounce; an answer already known comes at once.
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
		reset( context );
		showRecent( context );
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
 * Puts an answer on screen, when the visitor is still in the field. The
 * phone's sheet shows fewer suggestions per group.
 *
 * @param {Object} context
 * @param {string} query
 * @param {Object} full    The answer, as remembered.
 */
function show( context, query, full ) {
	const answer = context.sheet
		? limitAnswer( full, context.mobileLimit )
		: full;

	context.recent = [];
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
	context.open = context.focused || context.sheet;
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
 * Shows the recent searches in place of suggestions, when the field is empty.
 *
 * @param {Object} context
 */
function showRecent( context ) {
	const list = context.recentEnabled ? load( storage, context.field ) : [];

	context.recent = recentSuggestions( list, context.id, ( query ) =>
		allUrl( context.action, context.param, query )
	);
	context.options = context.recent.map( ( item ) => ( {
		id: item.optionId,
		url: item.url,
		query: item.query,
	} ) );
	context.activeId = '';
	context.open = context.sheet || context.recent.length > 0;
}

/**
 * Remembers a search the visitor made or followed.
 *
 * @param {Object} context
 * @param {string} query
 */
function remember( context, query ) {
	const text = normalize( query );
	if ( context.recentEnabled && text.length >= context.minChars ) {
		add( storage, context.field, text );
	}
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

/**
 * Closes the panel: a sheet closes only by its own means.
 *
 * @param {Object} context
 */
function close( context ) {
	context.activeId = '';
	if ( ! context.sheet ) {
		context.open = false;
	}
}

function cancel( context ) {
	const run = runtime.get( context.id );
	run?.controller?.abort();
	context.busy = false;
}

/**
 * Opens the full-screen sheet: the field takes the screen, the page behind
 * stays where it was, the back button closes it, and its height follows the
 * visual viewport so that the keyboard never covers the suggestions.
 *
 * @param {Object} context
 */
function openSheet( context ) {
	const run = runtime.get( context.id );
	const root = document.getElementById( context.id );
	const layer = root.querySelector( '.meiliscout-suggest__layer' );

	// The field leaves its place: its height stays, the page does not jump
	root.style.minHeight = `${ root.offsetHeight }px`;
	document.documentElement.classList.add( LOCKED );
	context.sheet = true;
	context.open = true;

	const fit = () => {
		const viewport = window.visualViewport;
		if ( viewport ) {
			layer.style.setProperty(
				'--meiliscout-suggest-height',
				`${ viewport.height }px`
			);
			layer.style.setProperty(
				'--meiliscout-suggest-top',
				`${ viewport.offsetTop }px`
			);
		}
	};
	fit();
	window.visualViewport?.addEventListener( 'resize', fit );
	window.visualViewport?.addEventListener( 'scroll', fit );
	run.viewport = fit;

	window.history.pushState( { meiliscoutSuggest: context.id }, '' );
	run.pushed = true;
}

/**
 * Closes the sheet.
 *
 * @param {Object}  context
 * @param {boolean} popped  The back button closed it: its history entry is gone.
 */
function closeSheet( context, popped = false ) {
	if ( ! context.sheet ) {
		return;
	}

	const run = runtime.get( context.id );
	const root = document.getElementById( context.id );
	const layer = root.querySelector( '.meiliscout-suggest__layer' );

	context.sheet = false;
	context.open = false;
	context.activeId = '';
	context.focused = false;
	cancel( context );
	document.documentElement.classList.remove( LOCKED );
	root.style.minHeight = '';
	layer.style.removeProperty( '--meiliscout-suggest-height' );
	layer.style.removeProperty( '--meiliscout-suggest-top' );
	window.visualViewport?.removeEventListener( 'resize', run.viewport );
	window.visualViewport?.removeEventListener( 'scroll', run.viewport );
	document.getElementById( `${ context.id }-input` )?.blur();

	// Its history entry goes with it, unless the back button already took it
	if ( run.pushed && ! popped ) {
		run.ignorePop = true;
		window.history.back();
	}
	run.pushed = false;
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
