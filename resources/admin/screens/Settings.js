import {
	createInterpolateElement,
	useEffect,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { post, errorMessage } from '../api';
import {
	Banner,
	Icon,
	Skeleton,
	Switch,
	useResource,
	useToast,
} from '../components';

const REALTIME_MODES = [
	{
		id: 'shutdown',
		title: __( 'At the end of the request', 'meiliscout' ),
		note: __( 'recommended', 'meiliscout' ),
		text: __(
			'One update per content, sent once the page is served.',
			'meiliscout'
		),
	},
	{
		id: 'async',
		title: __( 'Async queue', 'meiliscout' ),
		text: __(
			'Grouped and sent by WP-Cron every 5 minutes. For very busy sites.',
			'meiliscout'
		),
	},
	{
		id: 'off',
		title: __( 'Off', 'meiliscout' ),
		text: __( 'Only full indexations update the indexes.', 'meiliscout' ),
	},
];

const Locked = ( { name } ) => (
	<span className="ms-field__help">
		<Icon name="lock" size={ 14 } />
		{ createInterpolateElement(
			__(
				'Set by <name /> in the environment or wp-config.php',
				'meiliscout'
			),
			{ name: <code>{ name }</code> }
		) }
	</span>
);

const fromData = ( data ) => ( {
	host: data.host.value,
	admin_key: '',
	search_key: '',
	prefix: data.prefix.value,
	realtime: data.realtime.value,
	timeout: data.timeout,
	batch_size: data.batch_size,
	max_total_hits: data.max_total_hits,
	contains_filter: data.contains_filter.enabled,
	query_integration: data.query_integration,
	term_query_integration: data.term_query_integration,
} );

const TERM_INTEGRATIONS = [
	{
		id: 'search',
		title: __( 'Term searches of the classic editor', 'meiliscout' ),
		text: __(
			'The tag box suggestions (ajax-tag-search). Needs Partial filters on fields, below, to match as MySQL does; MySQL otherwise.',
			'meiliscout'
		),
	},
	{
		id: 'rest_search',
		title: __( 'REST API term searches', 'meiliscout' ),
		text: __(
			"/wp/v2/categories?search= and the other indexed taxonomies: the block editor's category and tag panels.",
			'meiliscout'
		),
	},
	{
		id: 'admin',
		title: __( 'Admin term lists', 'meiliscout' ),
		text: __(
			'The lists of categories, tags and other terms in the admin.',
			'meiliscout'
		),
	},
];

const INTEGRATIONS = [
	{
		id: 'search',
		title: __( 'Site search', 'meiliscout' ),
		text: __( 'The search results page.', 'meiliscout' ),
	},
	{
		id: 'archives',
		title: __( 'Archives', 'meiliscout' ),
		text: __(
			'Post type archives, categories, tags and custom taxonomies.',
			'meiliscout'
		),
	},
	{
		id: 'rest_search',
		title: __( 'REST API searches', 'meiliscout' ),
		text: __(
			"/wp/v2/posts?search= and the other indexed post types: the block editor's link search, headless front ends.",
			'meiliscout'
		),
	},
	{
		id: 'admin',
		title: __( 'Admin lists', 'meiliscout' ),
		text: __(
			'The lists of posts in the admin. Drafts are not indexed: lists that show them run on MySQL.',
			'meiliscout'
		),
	},
];

const ConnectionTest = ( { result } ) => {
	if ( ! result ) {
		return null;
	}
	if ( ! result.ok ) {
		return (
			<span role="status" className="ms-status ms-status--error">
				{ result.error }
			</span>
		);
	}

	const parts = [
		sprintf(
			/* translators: %s: the Meilisearch version */
			__( 'Meilisearch v%s answers', 'meiliscout' ),
			result.version
		),
		__( 'admin key valid', 'meiliscout' ),
	];

	if ( result.search_key ) {
		parts.push(
			sprintf(
				/* translators: 1: indexes the key can search, 2: indexes in all */
				__(
					'search key: can search %1$d of the %2$d indexes',
					'meiliscout'
				),
				result.search_key.readable,
				result.search_key.total
			)
		);
	}

	return (
		<span
			role="status"
			className={
				'ms-status ' +
				( result.search_key &&
				result.search_key.readable < result.search_key.total
					? 'ms-status--error'
					: 'ms-status--success' )
			}
		>
			{ parts.join( ' · ' ) }
		</span>
	);
};

const Settings = ( { refreshOverview, overview } ) => {
	const resource = useResource( '/settings' );
	const { data } = resource;
	const [ form, setForm ] = useState( null );
	const [ showKey, setShowKey ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ testing, setTesting ] = useState( false );
	const [ test, setTest ] = useState( null );
	const [ toast, showToast ] = useToast();

	useEffect( () => {
		if ( data ) {
			setForm( fromData( data ) );
		}
	}, [ data ] );

	if ( resource.error && ! data ) {
		return (
			<div className="ms-wrap ms-page">
				<Banner tone="error" icon="alert" title={ resource.error } />
			</div>
		);
	}

	if ( ! data || ! form ) {
		return (
			<div className="ms-wrap ms-page" aria-busy="true">
				<Skeleton height={ 320 } />
			</div>
		);
	}

	const set = ( field ) => ( event ) =>
		setForm( { ...form, [ field ]: event.target.value } );
	const configured = overview.data?.connection?.configured;
	const typedPrefix = form.prefix.trim();
	const prefix = typedPrefix
		? typedPrefix
				.toLowerCase()
				.replace( /[^a-z0-9_-]+/g, '_' )
				.replace( /^[_-]+|[_-]+$/g, '' )
		: data.prefix.effective;
	const prefixChanged = prefix !== data.prefix.effective;

	const runTest = () => {
		setTesting( true );
		setTest( null );
		post( '/settings/test', {
			host: form.host,
			admin_key: form.admin_key,
			search_key: form.search_key,
		} )
			.then( setTest )
			.catch( ( error ) =>
				setTest( { ok: false, error: errorMessage( error ) } )
			)
			.finally( () => setTesting( false ) );
	};

	const save = ( event ) => {
		event.preventDefault();
		setSaving( true );
		post( '/settings', form )
			.then( ( result ) => {
				resource.setData( result );
				refreshOverview();
				showToast( __( 'Settings saved.', 'meiliscout' ) );
			} )
			.catch( ( error ) => showToast( errorMessage( error ), 'error' ) )
			.finally( () => setSaving( false ) );
	};

	const clearSearchKey = () =>
		post( '/settings', { clear_search_key: true } )
			.then( ( result ) => {
				resource.setData( result );
				refreshOverview();
			} )
			.catch( ( error ) => showToast( errorMessage( error ), 'error' ) );

	return (
		<form className="ms-wrap ms-page ms-page--with-side" onSubmit={ save }>
			{ toast }
			<nav
				className="ms-side"
				aria-label={ __( 'Sections of the settings', 'meiliscout' ) }
			>
				<a href="#/settings#connection">
					{ __( 'Connection', 'meiliscout' ) }
				</a>
				<a href="#/settings#indexes">
					{ __( 'Index names', 'meiliscout' ) }
				</a>
				<a href="#/settings#realtime">
					{ __( 'Real time', 'meiliscout' ) }
				</a>
				<a href="#/settings#queries">
					{ __( 'Queries', 'meiliscout' ) }
				</a>
				<a href="#/settings#advanced">
					{ __( 'Advanced', 'meiliscout' ) }
				</a>
			</nav>

			<div className="ms-main">
				{ ! configured && (
					<Banner
						tone="info"
						icon="info"
						title={ __( 'Welcome to MeiliScout', 'meiliscout' ) }
					>
						<p>
							{ __(
								'Connect your Meilisearch instance to start: its URL, and an API key allowed to write to the indexes.',
								'meiliscout'
							) }
						</p>
					</Banner>
				) }

				<section
					id="connection"
					className="ms-card"
					aria-labelledby="ms-connection-title"
				>
					<div className="ms-card__head">
						<h2 id="ms-connection-title">
							{ __( 'Connection', 'meiliscout' ) }
						</h2>
					</div>
					<div className="ms-card__body">
						<div className="ms-field">
							<label htmlFor="ms-host">
								{ __( 'Instance URL', 'meiliscout' ) }
							</label>
							<input
								id="ms-host"
								className="ms-input"
								type="url"
								value={ form.host }
								onChange={ set( 'host' ) }
								readOnly={ data.host.locked }
								placeholder="https://ms-xxxxxxxx.meilisearch.io"
							/>
							{ data.host.locked && <Locked name="MEILI_HOST" /> }
						</div>

						<div className="ms-field">
							<label htmlFor="ms-admin-key">
								{ __( 'Admin key', 'meiliscout' ) }
							</label>
							<div className="ms-field__row">
								<input
									id="ms-admin-key"
									className="ms-input"
									type={ showKey ? 'text' : 'password' }
									value={ form.admin_key }
									onChange={ set( 'admin_key' ) }
									readOnly={ data.admin_key.locked }
									autoComplete="off"
									placeholder={
										data.admin_key.set
											? __(
													'Saved · type a new key to replace it',
													'meiliscout'
											  )
											: ''
									}
								/>
								{ ! data.admin_key.locked && (
									<button
										type="button"
										className="ms-button"
										aria-controls="ms-admin-key"
										onClick={ () =>
											setShowKey( ! showKey )
										}
									>
										{ showKey
											? __( 'Hide', 'meiliscout' )
											: __( 'Show', 'meiliscout' ) }
									</button>
								) }
							</div>
							{ data.admin_key.locked ? (
								<Locked name="MEILI_KEY" />
							) : (
								<span className="ms-field__help">
									{ __(
										'Used on the server only, to write to the indexes. Never sent to the browser.',
										'meiliscout'
									) }
								</span>
							) }
						</div>

						<div className="ms-field">
							<label htmlFor="ms-search-key">
								{ __( 'Search key', 'meiliscout' ) }{ ' ' }
								<span className="ms-field__label-note">
									{ __( '(optional)', 'meiliscout' ) }
								</span>
							</label>
							<div className="ms-field__row">
								<input
									id="ms-search-key"
									className="ms-input"
									type="password"
									value={ form.search_key }
									onChange={ set( 'search_key' ) }
									readOnly={ data.search_key.locked }
									autoComplete="off"
									placeholder={
										data.search_key.set
											? __(
													'Saved · type a new key to replace it',
													'meiliscout'
											  )
											: ''
									}
								/>
								{ data.search_key.set &&
									! data.search_key.locked && (
										<button
											type="button"
											className="ms-button"
											onClick={ clearSearchKey }
										>
											{ __( 'Remove', 'meiliscout' ) }
										</button>
									) }
							</div>
							{ data.search_key.locked ? (
								<Locked name="MEILI_SEARCH_KEY" />
							) : (
								<span className="ms-field__help">
									{ __(
										'Used for searches. Without it, the admin key searches too.',
										'meiliscout'
									) }
								</span>
							) }
						</div>

						<div
							style={ {
								display: 'flex',
								flexWrap: 'wrap',
								alignItems: 'center',
								gap: 12,
							} }
						>
							<button
								type="button"
								className="ms-button"
								onClick={ runTest }
								disabled={ testing }
							>
								{ testing
									? __( 'Testing…', 'meiliscout' )
									: __(
											'Test the connection',
											'meiliscout'
									  ) }
							</button>
							<ConnectionTest result={ test } />
						</div>
					</div>
				</section>

				<section
					id="indexes"
					className="ms-card"
					aria-labelledby="ms-indexes-title"
				>
					<div className="ms-card__head">
						<h2 id="ms-indexes-title">
							{ __( 'Index names', 'meiliscout' ) }
						</h2>
						<p>
							{ __(
								'One prefix per site: several sites, staging included, can share an instance without mixing up.',
								'meiliscout'
							) }
						</p>
					</div>
					<div className="ms-card__body">
						<div className="ms-field" style={ { maxWidth: 420 } }>
							<label htmlFor="ms-prefix">
								{ __( 'Prefix', 'meiliscout' ) }
							</label>
							<input
								id="ms-prefix"
								className="ms-input"
								type="text"
								value={ form.prefix }
								onChange={ set( 'prefix' ) }
								readOnly={ data.prefix.locked }
								placeholder={ data.prefix.effective }
							/>
							{ data.prefix.locked ? (
								<Locked name="MEILI_INDEX_PREFIX" />
							) : (
								<span className="ms-field__help">
									{ __(
										'The site’s domain by default.',
										'meiliscout'
									) }
								</span>
							) }
						</div>
						<div className="ms-names">
							<span className="ms-hint">
								{ __( 'Indexes:', 'meiliscout' ) }
							</span>
							{ [ 'posts', 'taxonomies' ].map( ( base ) => (
								<code key={ base }>
									{ ( prefix ? prefix + '_' : '' ) + base }
								</code>
							) ) }
						</div>
						{ prefixChanged && (
							<p className="ms-inline-note">
								{ __(
									'Once saved, searches keep using the current indexes until the next full indexation, which creates the new ones.',
									'meiliscout'
								) }
							</p>
						) }
					</div>
				</section>

				<section
					id="realtime"
					className="ms-card"
					aria-labelledby="ms-realtime-title"
				>
					<div className="ms-card__head">
						<h2 id="ms-realtime-title">
							{ __( 'Real-time indexing', 'meiliscout' ) }
						</h2>
						<p>
							{ __(
								'When content is saved, edited or deleted.',
								'meiliscout'
							) }
						</p>
					</div>
					<div className="ms-card__body" style={ { gap: 12 } }>
						<fieldset className="ms-choices">
							<legend className="ms-visually-hidden">
								{ __(
									'Real-time indexing mode',
									'meiliscout'
								) }
							</legend>
							{ REALTIME_MODES.map( ( mode ) => (
								<label
									className="ms-choice"
									key={ mode.id }
									htmlFor={ 'ms-realtime-' + mode.id }
								>
									<input
										id={ 'ms-realtime-' + mode.id }
										type="radio"
										name="ms-realtime"
										value={ mode.id }
										checked={ form.realtime === mode.id }
										onChange={ set( 'realtime' ) }
										disabled={ data.realtime.locked }
									/>
									<span className="ms-choice__text">
										<span className="ms-choice__title">
											{ mode.title }
											{ mode.note && (
												<span> · { mode.note }</span>
											) }
										</span>
										<span className="ms-choice__desc">
											{ mode.text }
										</span>
									</span>
								</label>
							) ) }
						</fieldset>
						{ data.realtime.locked && (
							<Locked name="MEILISCOUT_ASYNC_INDEXING" />
						) }
					</div>
				</section>

				<section
					id="queries"
					className="ms-card"
					aria-labelledby="ms-queries-title"
				>
					<div className="ms-card__head">
						<h2 id="ms-queries-title">
							{ __( 'Queries', 'meiliscout' ) }
						</h2>
						<p>
							{ createInterpolateElement(
								__(
									'Queries Meilisearch serves without asking. Any query can ask with <code>use_meilisearch</code>, or stay on MySQL with <code>use_meilisearch => false</code>. A query Meilisearch cannot answer as MySQL would runs on MySQL.',
									'meiliscout'
								),
								{ code: <code /> }
							) }
						</p>
					</div>
					{ INTEGRATIONS.map( ( integration ) => (
						<div className="ms-row" key={ integration.id }>
							<Switch
								checked={
									form.query_integration[ integration.id ]
								}
								onChange={ ( on ) =>
									setForm( {
										...form,
										query_integration: {
											...form.query_integration,
											[ integration.id ]: on,
										},
									} )
								}
								label={ integration.title }
							/>
							<div className="ms-row__label ms-row__label--text">
								<strong>{ integration.title }</strong>
								<span>{ integration.text }</span>
							</div>
						</div>
					) ) }
					<div className="ms-card__head ms-card__head--sub">
						<h3>{ __( 'Term queries', 'meiliscout' ) }</h3>
						<p>
							{ __(
								'get_terms() calls Meilisearch serves without asking. Terms are read from the taxonomies index: turn the taxonomies on in Content.',
								'meiliscout'
							) }
						</p>
					</div>
					{ TERM_INTEGRATIONS.map( ( integration ) => (
						<div
							className="ms-row"
							key={ 'term-' + integration.id }
						>
							<Switch
								checked={
									form.term_query_integration[
										integration.id
									]
								}
								onChange={ ( on ) =>
									setForm( {
										...form,
										term_query_integration: {
											...form.term_query_integration,
											[ integration.id ]: on,
										},
									} )
								}
								label={ integration.title }
							/>
							<div className="ms-row__label ms-row__label--text">
								<strong>{ integration.title }</strong>
								<span>{ integration.text }</span>
							</div>
						</div>
					) ) }
				</section>

				<section
					id="advanced"
					className="ms-card"
					aria-labelledby="ms-advanced-title"
				>
					<div className="ms-card__head">
						<h2 id="ms-advanced-title">
							{ __( 'Advanced', 'meiliscout' ) }
						</h2>
					</div>
					<div className="ms-card__body">
						<div className="ms-form-grid">
							<div className="ms-field">
								<label htmlFor="ms-timeout">
									{ __( 'Timeout (seconds)', 'meiliscout' ) }
								</label>
								<input
									id="ms-timeout"
									className="ms-input"
									type="number"
									min="1"
									value={ form.timeout }
									onChange={ set( 'timeout' ) }
								/>
							</div>
							<div className="ms-field">
								<label htmlFor="ms-batch">
									{ __( 'Contents per batch', 'meiliscout' ) }
								</label>
								<input
									id="ms-batch"
									className="ms-input"
									type="number"
									min="50"
									step="50"
									value={ form.batch_size }
									onChange={ set( 'batch_size' ) }
								/>
							</div>
							<div className="ms-field">
								<label htmlFor="ms-max-hits">
									{ __(
										'Maximum results per query',
										'meiliscout'
									) }
								</label>
								<input
									id="ms-max-hits"
									className="ms-input"
									type="number"
									min="100"
									step="100"
									value={ form.max_total_hits }
									onChange={ set( 'max_total_hits' ) }
									aria-describedby="ms-max-hits-help"
								/>
								<span
									id="ms-max-hits-help"
									className="ms-field__help"
								>
									{ __(
										'A query for all posts (posts_per_page -1) stops there, and so does paging. Higher values cost memory on large sites.',
										'meiliscout'
									) }
								</span>
							</div>
						</div>
						{ data.contains_filter.available && (
							<div className="ms-row">
								<Switch
									checked={ form.contains_filter }
									onChange={ ( on ) =>
										setForm( {
											...form,
											contains_filter: on,
										} )
									}
									label={ __(
										'Partial filters on fields (LIKE)',
										'meiliscout'
									) }
								/>
								<div className="ms-row__label ms-row__label--text">
									<strong>
										{ __(
											'Partial filters on fields (LIKE)',
											'meiliscout'
										) }
									</strong>
									<span>
										{ __(
											'Serves meta_query LIKE and NOT LIKE with the CONTAINS filter, an experimental feature of Meilisearch turned on for the whole instance. Off, these queries run on MySQL.',
											'meiliscout'
										) }
									</span>
								</div>
								<span className="ms-row__meta">
									{ data.contains_filter.enabled
										? __(
												'On on the instance',
												'meiliscout'
										  )
										: __(
												'Off on the instance',
												'meiliscout'
										  ) }
								</span>
							</div>
						) }
					</div>
				</section>

				<div style={ { display: 'flex', justifyContent: 'flex-end' } }>
					<button
						type="submit"
						className="ms-button ms-button--primary ms-button--large"
						disabled={ saving }
					>
						{ saving
							? __( 'Saving…', 'meiliscout' )
							: __( 'Save the settings', 'meiliscout' ) }
					</button>
				</div>
			</div>
		</form>
	);
};

export default Settings;
