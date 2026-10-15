import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import './setup.css';
import { get, post, errorMessage } from '../api';
import { Icon, Switch, useResource } from '../components';
import { number } from '../format';
import {
	Confetti,
	DocsScene,
	DoneScene,
	IndexScene,
	ListingScene,
	PlugScene,
	QueryScene,
	SearchScene,
} from './scenes';

/* The setup assistant: a full-screen walk through the main settings. Each step
   saves what it asks as soon as it is left, so leaving halfway keeps the work. */

// The assistant suggests the most used custom fields, not all of them
const MAX_KEYS = 8;

// What WordPress keeps for itself: nobody filters on it
const INTERNAL_KEY =
	/^_(wp_|edit_|oembed|thumbnail_id|menu_item|pingme|encloseme|last_edit)/;

const GUIDE_URL =
	'https://github.com/AmphiBee/MeiliScout/blob/main/docs/LISTINGS_GUIDE.md';

const REALTIME = [
	{
		id: 'shutdown',
		title: __( 'Instantly', 'meiliscout' ),
		note: __( 'recommended', 'meiliscout' ),
		text: __(
			'Each change is sent once the page has been served. Nobody waits for it.',
			'meiliscout'
		),
	},
	{
		id: 'async',
		title: __( 'In the background', 'meiliscout' ),
		text: __(
			'Changes are grouped and sent by WP-Cron every five minutes. For very busy sites.',
			'meiliscout'
		),
	},
	{
		id: 'off',
		title: __( 'Only when I say so', 'meiliscout' ),
		text: __(
			'Only a full indexation updates the search. For sites that import in bulk.',
			'meiliscout'
		),
	},
];

const SERVED = [
	{
		id: 'search',
		title: __( 'The site search', 'meiliscout' ),
		text: __(
			'The search results page of the site, ranked by Meilisearch.',
			'meiliscout'
		),
	},
	{
		id: 'archives',
		title: __( 'Archives', 'meiliscout' ),
		text: __(
			'Post type, category, tag and taxonomy archives.',
			'meiliscout'
		),
	},
	{
		id: 'rest_search',
		title: __( 'REST searches', 'meiliscout' ),
		text: __(
			'The block editor’s link search and headless front-ends.',
			'meiliscout'
		),
	},
];

/**
 * A post type or taxonomy that is on by default the first time.
 *
 * @param {Object} item A post type or taxonomy of the catalog.
 * @return {boolean} Whether to tick it.
 */
const suggested = ( item ) => item.name !== 'attachment' && item.count > 0;

/**
 * The rail's class for a step: done, current, or to come.
 *
 * @param {number} step    A step's position.
 * @param {number} current The current step's position.
 * @return {string} The class.
 */
const railClass = ( step, current ) => {
	if ( step < current ) {
		return 'is-done';
	}

	return step === current ? 'is-current' : '';
};

/**
 * How the Meilisearch check shows on the first screen.
 *
 * @param {Object|undefined} connection What the overview knows of the connection.
 * @return {Object} { state, icon, label }
 */
const meiliCheck = ( connection ) => {
	if ( ! connection?.configured ) {
		return {
			state: 'wait',
			icon: 'search',
			label: __( 'Meilisearch to connect', 'meiliscout' ),
		};
	}

	if ( ! connection.reachable ) {
		return {
			state: 'bad',
			icon: 'alert',
			label: __( 'Meilisearch unreachable', 'meiliscout' ),
		};
	}

	return {
		state: 'ok',
		icon: 'check',
		label: sprintf(
			/* translators: %s: the Meilisearch version */
			__( 'Meilisearch %s', 'meiliscout' ),
			connection.version ?? ''
		),
	};
};

const Step = ( { id, title, lead, children, step, total } ) => (
	<>
		<p className="ms-setup__eyebrow">
			{ sprintf(
				/* translators: 1: the step's number, 2: how many steps */
				__( 'Step %1$d of %2$d', 'meiliscout' ),
				step,
				total
			) }
		</p>
		<h2
			id="ms-setup-title"
			className="ms-setup__title"
			tabIndex="-1"
			data-step={ id }
		>
			{ title }
		</h2>
		<p className="ms-setup__lead">{ lead }</p>
		<div className="ms-setup__content">{ children }</div>
	</>
);

const Choice = ( {
	checked,
	onChange,
	title,
	note,
	text,
	disabled,
	type = 'checkbox',
	name,
} ) => (
	<label
		className={
			'ms-setup-choice' +
			( checked ? ' is-on' : '' ) +
			( disabled ? ' is-disabled' : '' )
		}
	>
		<input
			type={ type }
			name={ name }
			checked={ checked }
			onChange={ onChange }
			disabled={ disabled }
		/>
		<span className="ms-setup-choice__tick">
			<Icon name="check" size={ 14 } strokeWidth={ 3.2 } />
		</span>
		<span className="ms-setup-choice__text">
			<strong>
				{ title }
				{ note && <em>{ note }</em> }
			</strong>
			{ text && <span>{ text }</span> }
		</span>
	</label>
);

const Setup = ( { overview, refreshOverview, onExit } ) => {
	const setup = useResource( '/setup' );
	const settings = useResource( '/settings' );
	const content = useResource( '/content' );
	const [ index, setIndex ] = useState( 0 );
	const [ direction, setDirection ] = useState( 'forward' );
	const [ busy, setBusy ] = useState( false );
	const [ problem, setProblem ] = useState( null );
	const [ draft, setDraft ] = useState( null );
	const [ test, setTest ] = useState( { status: 'idle' } );
	const [ showKey, setShowKey ] = useState( false );
	const [ keys, setKeys ] = useState( [] );
	const [ keysTotal, setKeysTotal ] = useState( 0 );
	const [ run, setRun ] = useState( null );
	const [ totals, setTotals ] = useState( null );
	const [ started, setStarted ] = useState( false );
	const stage = useRef();

	const patch = ( changes ) =>
		setDraft( ( current ) => ( { ...current, ...changes } ) );

	// What the site already has, once it is loaded: the assistant starts from it
	useEffect( () => {
		if ( draft || ! settings.data || ! content.data || ! setup.data ) {
			return;
		}

		const types = content.data.post_types;
		const taxonomies = content.data.taxonomies;
		const fresh = ! types.some( ( type ) => type.selected );
		const served = settings.data.query_integration;

		setDraft( {
			host: '',
			adminKey: '',
			searchKey: '',
			types: types
				.filter( ( type ) =>
					fresh ? suggested( type ) : type.selected
				)
				.map( ( type ) => type.name ),
			taxonomies: taxonomies
				.filter( ( taxonomy ) =>
					fresh ? taxonomy.count > 0 : taxonomy.selected
				)
				.map( ( taxonomy ) => taxonomy.name ),
			metaKeys: content.data.meta_keys.map( ( key ) => key.key ),
			realtime: settings.data.realtime.value,
			// A first run starts with the search on: it is what people came for
			served: {
				search:
					served.search ||
					( fresh && ! served.archives && ! served.rest_search ),
				archives: served.archives,
				rest_search: served.rest_search,
			},
			listings: settings.data.listings.enabled,
		} );
	}, [ draft, settings.data, content.data, setup.data ] );

	// The wire between WordPress and Meilisearch: tested as the fields are typed
	const stepId = [
		'welcome',
		'connect',
		'content',
		'behaviour',
		'listings',
		'index',
		'done',
	][ index ];
	useEffect( () => {
		if ( stepId !== 'connect' || ! draft ) {
			return undefined;
		}

		setTest( ( current ) => ( { ...current, status: 'testing' } ) );
		let cancelled = false;
		const timer = window.setTimeout(
			() =>
				post( '/settings/test', {
					host: draft.host,
					admin_key: draft.adminKey,
					search_key: draft.searchKey,
				} )
					.then(
						( result ) =>
							! cancelled &&
							setTest( {
								...result,
								status: result.ok ? 'ok' : 'error',
							} )
					)
					.catch(
						( error ) =>
							! cancelled &&
							setTest( {
								status: 'error',
								error: errorMessage( error ),
							} )
					),
			draft.host || draft.adminKey || draft.searchKey ? 700 : 150
		);

		return () => {
			cancelled = true;
			window.clearTimeout( timer );
		};
	}, [ stepId, draft?.host, draft?.adminKey, draft?.searchKey ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Custom fields the chosen types have, the most used first
	useEffect( () => {
		if ( stepId !== 'content' || ! draft || draft.types.length === 0 ) {
			return;
		}

		get( '/meta-keys', { post_types: draft.types } )
			.then( ( result ) => {
				setKeysTotal(
					result.keys.filter(
						( key ) => ! INTERNAL_KEY.test( key.key )
					).length + Math.max( 0, result.total - result.keys.length )
				);
				setKeys(
					result.keys
						.filter( ( key ) => ! INTERNAL_KEY.test( key.key ) )
						.sort( ( a, b ) => b.posts - a.posts )
						.slice( 0, MAX_KEYS )
				);
			} )
			.catch( () => setKeys( [] ) );
	}, [ stepId, draft?.types?.join( ',' ) ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// The indexation: its totals, and its progress once started
	useEffect( () => {
		if ( stepId !== 'index' ) {
			return undefined;
		}

		let cancelled = false;
		let timer;
		const poll = () =>
			get( '/indexation' )
				.then( ( result ) => {
					if ( cancelled ) {
						return;
					}
					setTotals( result.totals );
					setRun( result.run );
					if (
						[ 'waiting', 'running' ].includes( result.run.status )
					) {
						timer = window.setTimeout( poll, 1000 );
					}
				} )
				.catch(
					() =>
						! cancelled &&
						( timer = window.setTimeout( poll, 2500 ) )
				);
		poll();

		return () => {
			cancelled = true;
			window.clearTimeout( timer );
		};
	}, [ stepId, started ] );

	// A finished assistant stays finished
	useEffect( () => {
		if ( stepId === 'done' ) {
			post( '/setup', { state: 'completed' } ).catch( () => {} );
			refreshOverview();
		}
	}, [ stepId ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// The page behind does not scroll; the heading takes the focus, for the keyboard and screen readers
	useEffect( () => {
		document.documentElement.classList.add( 'ms-setup-open' );

		return () =>
			document.documentElement.classList.remove( 'ms-setup-open' );
	}, [] );

	useEffect( () => {
		stage.current
			?.querySelector( '.ms-setup__title' )
			?.focus( { preventScroll: true } );
	}, [ index ] );

	if ( setup.error || settings.error || content.error ) {
		return (
			<div className="ms-setup ms-setup--error" role="alert">
				<div className="ms-setup__failure">
					<h2>
						{ __( 'The assistant could not start', 'meiliscout' ) }
					</h2>
					<p>
						{ errorMessage(
							setup.error || settings.error || content.error
						) }
					</p>
					<button
						type="button"
						className="ms-setup-button"
						onClick={ () => onExit( '#/settings' ) }
					>
						{ __( 'Open the settings', 'meiliscout' ) }
					</button>
				</div>
			</div>
		);
	}

	const checks = setup.data;
	const loading = ! draft;
	const total = 7;

	const catalogTypes = content.data?.post_types ?? [];
	const catalogTaxonomies = content.data?.taxonomies ?? [];
	const chosenTypes = catalogTypes.filter( ( type ) =>
		draft?.types.includes( type.name )
	);
	const documents = chosenTypes.reduce(
		( sum, type ) => sum + type.count,
		0
	);

	const hostLocked = settings.data?.host.locked;
	const keyLocked = settings.data?.admin_key.locked;
	const searchLocked = settings.data?.search_key.locked;

	const progress = ( () => {
		const indexes = run?.indexes ?? [];
		const sum = indexes.reduce(
			( all, item ) => all + ( item.total ?? 0 ),
			0
		);
		const done = indexes.reduce(
			( all, item ) =>
				all +
				( item.state === 'done'
					? item.total ?? 0
					: Math.min( item.done ?? 0, item.total ?? 0 ) ),
			0
		);

		// What an earlier run did is not this one's progress
		return started && sum > 0 ? Math.round( ( done / sum ) * 100 ) : 0;
	} )();
	const running = started && [ 'waiting', 'running' ].includes( run?.status );
	const finished = started && run?.status === 'completed';

	const saves = {
		connect: () => {
			const body = {};
			if ( draft.host && ! hostLocked ) {
				body.host = draft.host;
			}
			if ( draft.adminKey && ! keyLocked ) {
				body.admin_key = draft.adminKey;
			}
			if ( draft.searchKey && ! searchLocked ) {
				body.search_key = draft.searchKey;
			}

			return Object.keys( body ).length
				? post( '/settings', body ).then( () => settings.reload() )
				: Promise.resolve();
		},
		content: () =>
			post( '/content', {
				post_types: draft.types,
				taxonomies: draft.taxonomies,
				meta_keys: draft.metaKeys,
			} ).then( () => content.reload() ),
		behaviour: () => {
			const body = {
				query_integration: {
					...settings.data.query_integration,
					...draft.served,
				},
			};
			if ( ! settings.data.realtime.locked ) {
				body.realtime = draft.realtime;
			}

			return post( '/settings', body );
		},
		listings: () =>
			post( '/settings', {
				listings: { enabled: checks.listings && draft.listings },
			} ),
	};

	const canContinue = {
		welcome: true,
		connect: test.status === 'ok',
		content: draft?.types.length > 0,
		behaviour: true,
		listings: true,
		index: finished,
		done: true,
	}[ stepId ];

	const go = ( to ) => {
		setDirection( to > index ? 'forward' : 'back' );
		setIndex( to );
		setProblem( null );
	};

	const next = () => {
		if ( busy || loading || ! canContinue ) {
			return;
		}
		setBusy( true );
		setProblem( null );

		( saves[ stepId ]?.() ?? Promise.resolve() )
			.then( () => {
				if ( stepId === 'connect' ) {
					refreshOverview();
				}
				go( index + 1 );
			} )
			.catch( ( error ) => setProblem( errorMessage( error ) ) )
			.finally( () => setBusy( false ) );
	};

	const leave = ( hash ) => {
		const finish = () => onExit( hash );
		if ( stepId === 'done' ) {
			finish();
			return;
		}
		post( '/setup', { state: 'dismissed' } ).then( finish, finish );
	};

	const start = () => {
		setProblem( null );
		post( '/indexation', { mode: 'update' } )
			.then( () => setStarted( true ) )
			.catch( ( error ) => setProblem( errorMessage( error ) ) );
	};

	const titles = [
		__( 'Welcome', 'meiliscout' ),
		__( 'Connect', 'meiliscout' ),
		__( 'Content', 'meiliscout' ),
		__( 'Behavior', 'meiliscout' ),
		__( 'Listings', 'meiliscout' ),
		__( 'Index', 'meiliscout' ),
		__( 'Done', 'meiliscout' ),
	];

	const scene = {
		welcome: <SearchScene />,
		connect: <PlugScene status={ test.status } />,
		content: <DocsScene count={ documents } types={ chosenTypes } />,
		behaviour: <QueryScene served={ draft?.served ?? {} } />,
		listings: (
			<ListingScene on={ !! draft?.listings && checks?.listings } />
		),
		index: (
			<IndexScene
				percent={ finished ? 100 : progress }
				running={ running }
				done={ finished }
			/>
		),
		done: <DoneScene />,
	}[ stepId ];

	const toggle = ( field, value ) =>
		patch( {
			[ field ]: draft[ field ].includes( value )
				? draft[ field ].filter( ( item ) => item !== value )
				: [ ...draft[ field ], value ],
		} );

	const connection = overview?.data?.connection;
	const meili = meiliCheck( connection );
	let continueLabel = __( 'Continue', 'meiliscout' );
	if ( busy ) {
		continueLabel = __( 'Saving…', 'meiliscout' );
	} else if ( stepId === 'welcome' ) {
		continueLabel = __( 'Let’s go', 'meiliscout' );
	}

	return (
		<div
			className={ 'ms-setup ms-setup--' + stepId }
			role="dialog"
			aria-modal="true"
			aria-labelledby="ms-setup-title"
		>
			<div className="ms-setup__bg" aria-hidden="true">
				<span className="ms-blob ms-blob--a" />
				<span className="ms-blob ms-blob--b" />
				<span className="ms-blob ms-blob--c" />
				<span className="ms-grid-bg" />
			</div>

			<header className="ms-setup__top">
				<div className="ms-setup__brand">
					<span className="ms-setup__logo">
						<Icon name="search" size={ 20 } strokeWidth={ 2.6 } />
					</span>
					<strong>MeiliScout</strong>
				</div>
				{ stepId !== 'done' && (
					<button
						type="button"
						className="ms-setup__exit"
						onClick={ () => leave( '#/' ) }
					>
						{ __( 'Exit the assistant', 'meiliscout' ) }
						<Icon name="close" size={ 16 } />
					</button>
				) }
			</header>

			<div className="ms-setup__body">
				<nav
					className="ms-setup__rail"
					aria-label={ __( 'Steps', 'meiliscout' ) }
				>
					<ol>
						{ titles.map( ( title, i ) => (
							<li
								key={ title }
								className={ railClass( i, index ) }
								aria-current={
									i === index ? 'step' : undefined
								}
							>
								<span className="ms-setup__dot">
									{ i < index ? (
										<Icon
											name="check"
											size={ 13 }
											strokeWidth={ 3.4 }
										/>
									) : (
										i + 1
									) }
								</span>
								<span className="ms-setup__step-name">
									{ title }
								</span>
							</li>
						) ) }
					</ol>
				</nav>

				{ /* Enter continues, as a form would: the handler is a convenience, every control stays reachable */ }
				{ /* eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions */ }
				<main
					className="ms-setup__stage"
					ref={ stage }
					onKeyDown={ ( event ) => {
						if (
							event.key === 'Enter' &&
							! [ 'BUTTON', 'TEXTAREA', 'A' ].includes(
								event.target.tagName
							)
						) {
							event.preventDefault();
							next();
						}
					} }
				>
					<div
						key={ stepId }
						className={ 'ms-setup__panel is-' + direction }
					>
						{ loading && (
							<div className="ms-setup__loading" aria-busy="true">
								<span className="ms-spinner" />
							</div>
						) }

						{ ! loading && stepId === 'welcome' && (
							<Step
								id="welcome"
								step={ 1 }
								total={ total }
								title={ __(
									'Search that feels instant.',
									'meiliscout'
								) }
								lead={ __(
									'Connect Meilisearch, choose what to index and switch on the good parts. About three minutes, and everything can be changed later.',
									'meiliscout'
								) }
							>
								<ul className="ms-setup__checks">
									<li
										className={
											checks.php.ok ? 'is-ok' : 'is-bad'
										}
										style={ { '--i': 0 } }
									>
										<Icon
											name={
												checks.php.ok
													? 'check'
													: 'alert'
											}
											size={ 16 }
											strokeWidth={ 3 }
										/>
										PHP { checks.php.version }
									</li>
									<li
										className={
											checks.wordpress.ok
												? 'is-ok'
												: 'is-bad'
										}
										style={ { '--i': 1 } }
									>
										<Icon
											name={
												checks.wordpress.ok
													? 'check'
													: 'alert'
											}
											size={ 16 }
											strokeWidth={ 3 }
										/>
										WordPress { checks.wordpress.version }
									</li>
									<li
										className={ 'is-' + meili.state }
										style={ { '--i': 2 } }
									>
										<Icon
											name={ meili.icon }
											size={ 16 }
											strokeWidth={ 3 }
										/>
										{ meili.label }
									</li>
								</ul>
								{ ( ! checks.php.ok ||
									! checks.wordpress.ok ) && (
									<p className="ms-setup__warn">
										{ __(
											'This site is below the versions MeiliScout supports (PHP 8.2, WordPress 6.6): it may not work.',
											'meiliscout'
										) }
									</p>
								) }
							</Step>
						) }

						{ ! loading && stepId === 'connect' && (
							<Step
								id="connect"
								step={ 2 }
								total={ total }
								title={ __(
									'Plug in Meilisearch.',
									'meiliscout'
								) }
								lead={ __(
									'The address of your instance and an admin key. We check them as you type.',
									'meiliscout'
								) }
							>
								<div className="ms-setup-field">
									<label htmlFor="ms-setup-host">
										{ __( 'Instance URL', 'meiliscout' ) }
									</label>
									<input
										id="ms-setup-host"
										type="url"
										placeholder="https://search.example.com"
										value={
											hostLocked
												? settings.data.host.value
												: draft.host
										}
										readOnly={ hostLocked }
										onChange={ ( event ) =>
											patch( {
												host: event.target.value,
											} )
										}
										autoComplete="off"
										spellCheck="false"
									/>
									{ hostLocked ? (
										<small>
											<Icon name="lock" size={ 12 } />{ ' ' }
											{ __(
												'Set by MEILI_HOST on this server.',
												'meiliscout'
											) }
										</small>
									) : (
										! draft.host &&
										settings.data.host.value && (
											<small>
												{ sprintf(
													/* translators: %s: a URL */ __(
														'Saved: %s. Type to replace it.',
														'meiliscout'
													),
													settings.data.host.value
												) }
											</small>
										)
									) }
								</div>
								<div className="ms-setup-field">
									<label htmlFor="ms-setup-key">
										{ __( 'Admin key', 'meiliscout' ) }
									</label>
									<div className="ms-setup-field__row">
										<input
											id="ms-setup-key"
											type={
												showKey ? 'text' : 'password'
											}
											placeholder={
												keyLocked ||
												settings.data.admin_key.set
													? __(
															'Saved: type to replace it',
															'meiliscout'
													  )
													: __(
															'Paste the key',
															'meiliscout'
													  )
											}
											value={
												keyLocked ? '' : draft.adminKey
											}
											readOnly={ keyLocked }
											onChange={ ( event ) =>
												patch( {
													adminKey:
														event.target.value,
												} )
											}
											autoComplete="off"
											spellCheck="false"
										/>
										{ ! keyLocked && (
											<button
												type="button"
												className="ms-setup-link"
												onClick={ () =>
													setShowKey( ! showKey )
												}
											>
												{ showKey
													? __( 'Hide', 'meiliscout' )
													: __(
															'Show',
															'meiliscout'
													  ) }
											</button>
										) }
									</div>
									{ keyLocked && (
										<small>
											<Icon name="lock" size={ 12 } />{ ' ' }
											{ __(
												'Set by MEILI_KEY on this server.',
												'meiliscout'
											) }
										</small>
									) }
								</div>

								<div
									className={
										'ms-setup-status is-' + test.status
									}
									role="status"
								>
									{ test.status === 'testing' && (
										<>
											<span className="ms-spinner ms-spinner--small" />{ ' ' }
											{ __(
												'Checking the connection…',
												'meiliscout'
											) }
										</>
									) }
									{ test.status === 'ok' && (
										<>
											<Icon
												name="check"
												size={ 16 }
												strokeWidth={ 3 }
											/>{ ' ' }
											{ sprintf(
												/* translators: %s: a version number */ __(
													'Connected to Meilisearch %s.',
													'meiliscout'
												),
												test.version ?? ''
											) }
										</>
									) }
									{ test.status === 'error' && (
										<>
											<Icon name="alert" size={ 16 } />{ ' ' }
											{ test.error }
										</>
									) }
									{ test.status === 'idle' && ' ' }
								</div>

								<p className="ms-setup__tip">
									{ __(
										'No instance yet? Start one in a minute with Docker, or use Meilisearch Cloud.',
										'meiliscout'
									) }
									<code>
										docker run -p 7700:7700
										getmeili/meilisearch
									</code>
								</p>
							</Step>
						) }

						{ ! loading && stepId === 'content' && (
							<Step
								id="content"
								step={ 3 }
								total={ total }
								title={ __(
									'What should people find?',
									'meiliscout'
								) }
								lead={ __(
									'Pick the content to make searchable. Only published content is sent.',
									'meiliscout'
								) }
							>
								<div className="ms-setup-grid">
									{ catalogTypes.map( ( type ) => (
										<Choice
											key={ type.name }
											checked={ draft.types.includes(
												type.name
											) }
											onChange={ () =>
												toggle( 'types', type.name )
											}
											title={ type.label }
											text={ sprintf(
												/* translators: %s: a number of published items */
												_n(
													'%s published',
													'%s published',
													type.count,
													'meiliscout'
												),
												number( type.count )
											) }
										/>
									) ) }
								</div>

								{ catalogTaxonomies.length > 0 && (
									<>
										<h3 className="ms-setup__sub">
											{ __(
												'Their terms, searchable too',
												'meiliscout'
											) }
										</h3>
										<div className="ms-setup-pills">
											{ catalogTaxonomies.map(
												( taxonomy ) => (
													<button
														key={ taxonomy.name }
														type="button"
														className={
															draft.taxonomies.includes(
																taxonomy.name
															)
																? 'is-on'
																: ''
														}
														aria-pressed={ draft.taxonomies.includes(
															taxonomy.name
														) }
														onClick={ () =>
															toggle(
																'taxonomies',
																taxonomy.name
															)
														}
													>
														{ taxonomy.label }
													</button>
												)
											) }
										</div>
									</>
								) }

								{ keys.length > 0 && (
									<>
										<h3 className="ms-setup__sub">
											{ __(
												'Custom fields to filter and sort on',
												'meiliscout'
											) }
										</h3>
										<div className="ms-setup-pills">
											{ [
												...new Set( [
													...keys.map(
														( key ) => key.key
													),
													...draft.metaKeys,
												] ),
											].map( ( key ) => (
												<button
													key={ key }
													type="button"
													className={
														draft.metaKeys.includes(
															key
														)
															? 'is-on'
															: ''
													}
													aria-pressed={ draft.metaKeys.includes(
														key
													) }
													onClick={ () =>
														toggle(
															'metaKeys',
															key
														)
													}
												>
													{ key }
												</button>
											) ) }
										</div>
										{ keysTotal > keys.length && (
											<p className="ms-setup__tip">
												{ sprintf(
													/* translators: %d: how many custom fields are suggested */
													__(
														'The %d most used are suggested. Add the others later in MeiliScout › Content.',
														'meiliscout'
													),
													keys.length
												) }
											</p>
										) }
									</>
								) }
							</Step>
						) }

						{ ! loading && stepId === 'behaviour' && (
							<Step
								id="behaviour"
								step={ 4 }
								total={ total }
								title={ __(
									'How should search behave?',
									'meiliscout'
								) }
								lead={ __(
									'Keep the index fresh, and decide which queries Meilisearch answers on its own. A query it cannot answer exactly stays on MySQL: never a different result.',
									'meiliscout'
								) }
							>
								<h3 className="ms-setup__sub">
									{ __(
										'Keep the search up to date',
										'meiliscout'
									) }
								</h3>
								<div className="ms-setup-stack">
									{ REALTIME.map( ( mode ) => (
										<Choice
											key={ mode.id }
											type="radio"
											name="ms-setup-realtime"
											checked={
												draft.realtime === mode.id
											}
											onChange={ () =>
												patch( { realtime: mode.id } )
											}
											title={ mode.title }
											note={ mode.note }
											text={ mode.text }
											disabled={
												settings.data.realtime.locked
											}
										/>
									) ) }
								</div>
								{ settings.data.realtime.locked && (
									<p className="ms-setup__tip">
										<Icon name="lock" size={ 12 } />{ ' ' }
										{ __(
											'Set by MEILISCOUT_ASYNC_INDEXING on this server.',
											'meiliscout'
										) }
									</p>
								) }

								<h3 className="ms-setup__sub">
									{ __(
										'Let Meilisearch answer',
										'meiliscout'
									) }
								</h3>
								<div className="ms-setup-stack">
									{ SERVED.map( ( item ) => (
										<Choice
											key={ item.id }
											checked={
												!! draft.served[ item.id ]
											}
											onChange={ () =>
												patch( {
													served: {
														...draft.served,
														[ item.id ]:
															! draft.served[
																item.id
															],
													},
												} )
											}
											title={ item.title }
											text={ item.text }
										/>
									) ) }
								</div>
							</Step>
						) }

						{ ! loading && stepId === 'listings' && (
							<Step
								id="listings"
								step={ 5 }
								total={ total }
								title={ __(
									'Build filterable listings.',
									'meiliscout'
								) }
								lead={ __(
									'Facets with live counts, sorting, search and pagination, in the block editor or in code. Optional: you can turn it on later.',
									'meiliscout'
								) }
							>
								<div
									className={
										'ms-setup-feature' +
										( draft.listings && checks.listings
											? ' is-on'
											: '' )
									}
								>
									<div>
										<strong>
											{ __(
												'Front listings',
												'meiliscout'
											) }
										</strong>
										<span>
											{ checks.listings
												? __(
														'One URL per state, SEO, Polylang and WPML, works without JavaScript.',
														'meiliscout'
												  )
												: sprintf(
														/* translators: %s: a WordPress version */ __(
															'Needs WordPress %s or later.',
															'meiliscout'
														),
														'6.9'
												  ) }
										</span>
									</div>
									<Switch
										checked={
											!! draft.listings && checks.listings
										}
										disabled={ ! checks.listings }
										onChange={ ( on ) =>
											patch( { listings: on } )
										}
										label={ __(
											'Turn on the front listings',
											'meiliscout'
										) }
									/>
								</div>
								<ul className="ms-setup-bullets">
									<li>
										{ __(
											'The Filterable listing block, in any page.',
											'meiliscout'
										) }
									</li>
									<li>
										{ __(
											'Or PHP, Blade and Twig, part by part.',
											'meiliscout'
										) }
									</li>
									<li>
										{ __(
											'A look that follows your theme.',
											'meiliscout'
										) }
									</li>
								</ul>
								<p className="ms-setup__tip">
									<a
										href={ GUIDE_URL }
										target="_blank"
										rel="noreferrer"
									>
										{ __(
											'Read the step-by-step guide',
											'meiliscout'
										) }
									</a>
								</p>
							</Step>
						) }

						{ ! loading && stepId === 'index' && (
							<Step
								id="index"
								step={ 6 }
								total={ total }
								title={
									finished
										? __(
												'Your content is searchable.',
												'meiliscout'
										  )
										: __(
												'Index your content.',
												'meiliscout'
										  )
								}
								lead={
									finished
										? __(
												'Everything is in Meilisearch. From now on, changes follow on their own.',
												'meiliscout'
										  )
										: __(
												'Send your content to Meilisearch. The site keeps working while it does.',
												'meiliscout'
										  )
								}
							>
								{ totals && (
									<p className="ms-setup__figures">
										<strong>
											{ number(
												totals.posts + totals.terms
											) }
										</strong>{ ' ' }
										{ sprintf(
											/* translators: 1: a number of contents, 2: a number of terms */
											__(
												'items: %1$s contents and %2$s terms',
												'meiliscout'
											),
											number( totals.posts ),
											number( totals.terms )
										) }
									</p>
								) }
								{ ! started && (
									<button
										type="button"
										className="ms-setup-button ms-setup-button--big"
										onClick={ start }
									>
										{ __(
											'Start the indexation',
											'meiliscout'
										) }
									</button>
								) }
								{ running && (
									<p
										className="ms-setup__status"
										role="status"
									>
										<span className="ms-spinner ms-spinner--small" />{ ' ' }
										{ run?.status === 'waiting'
											? __(
													'Waiting for WP-Cron to pick it up…',
													'meiliscout'
											  )
											: sprintf(
													/* translators: %d: a percentage */ __(
														'Indexing… %d%%',
														'meiliscout'
													),
													progress
											  ) }
									</p>
								) }
								{ started && run?.status === 'error' && (
									<p className="ms-setup__warn" role="alert">
										{ run.error }
									</p>
								) }
								{ started && run?.status === 'stalled' && (
									<p className="ms-setup__warn" role="alert">
										{ __(
											'The indexation does not seem to advance: WP-Cron may not be running. You can run `wp meiliscout index` instead.',
											'meiliscout'
										) }
									</p>
								) }
								{ ! started && (
									<p className="ms-setup__tip">
										{ __(
											'Large site? Run it from the command line instead:',
											'meiliscout'
										) }
										<code>wp meiliscout index</code>
									</p>
								) }
							</Step>
						) }

						{ ! loading && stepId === 'done' && (
							<Step
								id="done"
								step={ 7 }
								total={ total }
								title={ __( 'You’re all set.', 'meiliscout' ) }
								lead={ __(
									'MeiliScout is connected and ready. Here is where to go next.',
									'meiliscout'
								) }
							>
								<ul className="ms-setup__summary">
									<li>
										<Icon
											name="check"
											size={ 16 }
											strokeWidth={ 3 }
										/>
										{ sprintf(
											/* translators: 1: a number of post types, 2: a number of taxonomies */
											__(
												'%1$d content types and %2$d taxonomies indexed',
												'meiliscout'
											),
											draft.types.length,
											draft.taxonomies.length
										) }
									</li>
									<li>
										<Icon
											name="check"
											size={ 16 }
											strokeWidth={ 3 }
										/>
										{ [
											'search',
											'archives',
											'rest_search',
										].some( ( id ) => draft.served[ id ] )
											? __(
													'Meilisearch answers your searches',
													'meiliscout'
											  )
											: __(
													'Queries ask Meilisearch when they say so',
													'meiliscout'
											  ) }
									</li>
									<li>
										<Icon
											name="check"
											size={ 16 }
											strokeWidth={ 3 }
										/>
										{ draft.listings && checks.listings
											? __(
													'Front listings are on',
													'meiliscout'
											  )
											: __(
													'Front listings are off for now',
													'meiliscout'
											  ) }
									</li>
								</ul>
								<div className="ms-setup-next">
									<button
										type="button"
										className="ms-setup-button ms-setup-button--big"
										onClick={ () => leave( '#/' ) }
									>
										{ __(
											'Open the dashboard',
											'meiliscout'
										) }
									</button>
									<button
										type="button"
										className="ms-setup-button ms-setup-button--ghost"
										onClick={ () => leave( '#/search' ) }
									>
										{ __(
											'Try the search preview',
											'meiliscout'
										) }
									</button>
								</div>
							</Step>
						) }

						{ problem && (
							<p className="ms-setup__warn" role="alert">
								{ problem }
							</p>
						) }
					</div>

					{ ! loading && stepId !== 'done' && (
						<footer className="ms-setup__nav">
							<button
								type="button"
								className="ms-setup-button ms-setup-button--ghost"
								onClick={ () => go( index - 1 ) }
								disabled={ index === 0 || busy }
							>
								{ __( 'Back', 'meiliscout' ) }
							</button>
							<span className="ms-setup__nav-gap" />
							{ stepId === 'welcome' && (
								<button
									type="button"
									className="ms-setup-link"
									onClick={ () => leave( '#/settings' ) }
								>
									{ __(
										'I’ll configure it myself',
										'meiliscout'
									) }
								</button>
							) }
							{ stepId === 'index' && ! finished && (
								<button
									type="button"
									className="ms-setup-link"
									onClick={ () => go( index + 1 ) }
									disabled={ running }
								>
									{ __( 'Skip for now', 'meiliscout' ) }
								</button>
							) }
							<button
								type="button"
								className="ms-setup-button"
								onClick={ next }
								disabled={
									! canContinue ||
									busy ||
									( stepId === 'index' && running )
								}
							>
								{ continueLabel }
								<Icon
									name="arrow"
									size={ 16 }
									strokeWidth={ 2.6 }
								/>
							</button>
						</footer>
					) }
				</main>

				<aside className="ms-setup__scene" aria-hidden="true">
					{ scene }
				</aside>
			</div>

			{ stepId === 'done' && <Confetti /> }
		</div>
	);
};

export default Setup;
