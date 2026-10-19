import {
	createInterpolateElement,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { get, post, errorMessage } from '../api';
import { Banner, Skeleton, Switch, useResource, useToast } from '../components';
import { number } from '../format';
import { href } from '../routes';
import Weights from './Weights';

const TYPE_LABELS = {
	number: __( 'Number', 'meiliscout' ),
	date: __( 'Date', 'meiliscout' ),
	boolean: __( 'Yes / no', 'meiliscout' ),
	list: __( 'List', 'meiliscout' ),
	text: __( 'Text', 'meiliscout' ),
	empty: __( 'Always empty', 'meiliscout' ),
};

const fromData = ( data ) => ( {
	post_types: data.post_types
		.filter( ( type ) => type.selected )
		.map( ( type ) => type.name ),
	taxonomies: data.taxonomies
		.filter( ( taxonomy ) => taxonomy.selected )
		.map( ( taxonomy ) => taxonomy.name ),
	meta_keys: data.meta_keys,
	term_meta_keys: data.term_meta_keys,
	weights: data.weights.types,
	index_private: data.index_private,
} );

const keysOnly = ( selection ) => ( {
	...selection,
	meta_keys: selection.meta_keys.map( ( key ) => key.key ),
	term_meta_keys: selection.term_meta_keys.map( ( key ) => key.key ),
} );

const sameSelection = ( a, b ) =>
	JSON.stringify( keysOnly( a ) ) === JSON.stringify( keysOnly( b ) );

const toggled = ( list, value, on ) =>
	on ? [ ...list, value ] : list.filter( ( item ) => item !== value );

/**
 * Where a meta key is found: "72 projects, 18 posts", "12 categories".
 *
 * @param {Object} key    The key, with its counts by post type or by taxonomy.
 * @param {Object} labels Post type or taxonomy labels by name.
 * @return {string} The description.
 */
const presence = ( key, labels ) => {
	const parts = Object.entries( key.post_types ?? key.taxonomies ?? {} ).map(
		( [ type, count ] ) =>
			number( count ) +
			' ' +
			( labels[ type ] ?? type ).toLocaleLowerCase()
	);

	if ( parts.length ) {
		return parts.join( ', ' );
	}

	return key.taxonomies
		? __( 'No term', 'meiliscout' )
		: __( 'No published content', 'meiliscout' );
};

const SwitchList = ( { items, selected, onToggle, meta } ) =>
	items.map( ( item ) => {
		const on = selected.includes( item.name );

		return (
			<div className="ms-row" key={ item.name }>
				<Switch
					checked={ on }
					onChange={ ( value ) => onToggle( item.name, value ) }
					label={ sprintf(
						/* translators: %s: a post type or taxonomy name */
						__( 'Index %s', 'meiliscout' ),
						item.label
					) }
				/>
				<div className="ms-row__label">
					<strong>{ item.label }</strong>
					<span>{ item.name }</span>
				</div>
				<span className="ms-row__meta">{ meta( item ) }</span>
			</div>
		);
	} );

/**
 * A search field for the fields to index: the named ones first (ACF, Meta Box,
 * WooCommerce, declared ones), grouped by where they come from, then the raw
 * meta keys of the content. A few are listed, never all: the admin searches.
 *
 * @param {Object}   props
 * @param {string}   props.kind    'post' or 'term'.
 * @param {string[]} props.scope   Post types, or taxonomies, whose keys are searched.
 * @param {string[]} props.exclude Keys already selected.
 * @param {number}   props.total   Number of keys in all.
 * @param {Object[]} props.sources Where named fields come from, with their count.
 * @param {Object}   props.labels  Post type or taxonomy labels by name.
 * @param {Function} props.onAdd   Called with the key picked.
 * @return {Element} The field.
 */
const MetaKeyPicker = ( {
	kind,
	scope,
	exclude,
	total,
	sources,
	labels,
	onAdd,
} ) => {
	const [ query, setQuery ] = useState( '' );
	const [ source, setSource ] = useState( '' );
	const [ open, setOpen ] = useState( false );
	const [ results, setResults ] = useState( [] );
	const [ matches, setMatches ] = useState( 0 );
	const [ active, setActive ] = useState( 0 );
	const inputId = 'ms-' + kind + '-meta-key';
	const listId = inputId + '-options';
	const timer = useRef();

	useEffect( () => {
		if ( ! open ) {
			return;
		}
		clearTimeout( timer.current );
		timer.current = setTimeout( () => {
			get( '/meta-keys', {
				search: query,
				exclude,
				source,
				...( kind === 'term'
					? { kind, taxonomies: scope }
					: { post_types: scope } ),
			} )
				.then( ( response ) => {
					setResults( response.keys );
					setMatches( response.total );
					setActive( 0 );
				} )
				.catch( () => setResults( [] ) );
		}, 200 );

		return () => clearTimeout( timer.current );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ query, source, open, scope.join(), exclude.join() ] );

	const add = ( key ) => {
		onAdd( key );
		setQuery( '' );
		setOpen( false );
	};

	const typed = query.trim();
	const addTyped = () => typed && add( { key: typed, type: null } );

	const onKeyDown = ( event ) => {
		if ( event.key === 'ArrowDown' ) {
			event.preventDefault();
			setOpen( true );
			setActive( ( index ) => Math.min( index + 1, results.length - 1 ) );
		} else if ( event.key === 'ArrowUp' ) {
			event.preventDefault();
			setActive( ( index ) => Math.max( index - 1, 0 ) );
		} else if ( event.key === 'Enter' ) {
			event.preventDefault();
			if ( open && results[ active ] ) {
				add( results[ active ] );
			} else {
				addTyped();
			}
		} else if ( event.key === 'Escape' ) {
			setOpen( false );
		}
	};

	// A heading where the fields' origin changes, once some are named
	const named = results.some( ( result ) => result.registered );
	const rows = [];
	let heading = null;
	results.forEach( ( result, index ) => {
		const group = result.registered ? result.group : null;
		if ( named && ( rows.length === 0 || group !== heading ) ) {
			rows.push( {
				heading:
					group ?? __( 'Other keys in your content', 'meiliscout' ),
			} );
			heading = group;
		}
		rows.push( { result, index } );
	} );

	return (
		<div className="ms-add">
			<label htmlFor={ inputId } className="ms-field__label">
				{ __( 'Add a field', 'meiliscout' ) }
			</label>
			{ sources.length > 0 && (
				<div
					className="ms-chips"
					role="group"
					aria-label={ __(
						'Where the fields come from',
						'meiliscout'
					) }
				>
					{ [
						{
							source: '',
							group: __( 'All', 'meiliscout' ),
							count: null,
						},
						...sources,
					].map( ( item ) => (
						<button
							key={ item.source }
							type="button"
							className="ms-chip"
							aria-pressed={ source === item.source }
							onClick={ () => {
								setSource( item.source );
								setOpen( true );
							} }
						>
							{ item.group }
							{ item.count !== null &&
								' · ' + number( item.count ) }
						</button>
					) ) }
				</div>
			) }
			<div className="ms-field__row ms-combobox">
				<div>
					<input
						id={ inputId }
						className="ms-input"
						type="text"
						role="combobox"
						autoComplete="off"
						aria-expanded={ open && results.length > 0 }
						aria-controls={ listId }
						aria-autocomplete="list"
						aria-activedescendant={
							open && results[ active ]
								? inputId + '-' + active
								: undefined
						}
						value={ query }
						placeholder={
							kind === 'term'
								? sprintf(
										/* translators: %s: number of meta keys */
										_n(
											'Search the %s key of your terms',
											'Search the %s keys of your terms',
											total,
											'meiliscout'
										),
										number( total )
								  )
								: sprintf(
										/* translators: %s: number of meta keys */
										_n(
											'Search by name or key (%s key in your content)',
											'Search by name or key (%s keys in your content)',
											total,
											'meiliscout'
										),
										number( total )
								  )
						}
						onChange={ ( event ) => {
							setQuery( event.target.value );
							setOpen( true );
						} }
						onFocus={ () => setOpen( true ) }
						onBlur={ () =>
							setTimeout( () => setOpen( false ), 150 )
						}
						onKeyDown={ onKeyDown }
					/>
					{ open && ( results.length > 0 || typed ) && (
						<ul
							className="ms-combobox__list"
							id={ listId }
							role="listbox"
							aria-label={ __( 'Fields', 'meiliscout' ) }
						>
							{ rows.map( ( row ) =>
								row.heading ? (
									<li
										key={ 'h-' + row.heading }
										role="presentation"
										className="ms-combobox__group"
									>
										{ row.heading }
									</li>
								) : (
									<li
										key={ row.result.key }
										id={ inputId + '-' + row.index }
										role="option"
										aria-selected={ row.index === active }
										className="ms-combobox__option"
										onMouseDown={ ( event ) => {
											event.preventDefault();
											add( row.result );
										} }
									>
										<span className="ms-combobox__name">
											{ row.result.label ? (
												<>
													<strong>
														{ row.result.label }
													</strong>
													<span className="ms-mono">
														{ row.result.key }
													</span>
												</>
											) : (
												<span className="ms-mono">
													{ row.result.key }
												</span>
											) }
										</span>
										<small>
											{ TYPE_LABELS[ row.result.type ] }
										</small>
										<small>
											{ row.result.registered &&
											! row.result.posts &&
											kind === 'post'
												? __(
														'Not used yet',
														'meiliscout'
												  )
												: presence(
														row.result,
														labels
												  ) }
										</small>
									</li>
								)
							) }
							{ results.length === 0 && (
								<li
									role="presentation"
									className="ms-combobox__more"
								>
									{ __(
										'No field matches. “Add” adds what you typed as a meta key.',
										'meiliscout'
									) }
								</li>
							) }
							{ matches > results.length && (
								<li
									role="presentation"
									className="ms-combobox__more"
								>
									{ sprintf(
										/* translators: %s: number of fields not listed */
										_n(
											'%s more: keep typing to narrow it down',
											'%s more: keep typing to narrow them down',
											matches - results.length,
											'meiliscout'
										),
										number( matches - results.length )
									) }
								</li>
							) }
						</ul>
					) }
				</div>
				<button
					type="button"
					className="ms-button"
					onClick={ addTyped }
					disabled={ ! typed }
				>
					{ __( 'Add', 'meiliscout' ) }
				</button>
			</div>
		</div>
	);
};

/**
 * The meta keys selected for posts or for terms: suggestions, table and picker.
 *
 * @param {Object}   props
 * @param {string}   props.kind      'post' or 'term'.
 * @param {Object[]} props.keys      The keys selected.
 * @param {string[]} props.missed    Keys queries asked for, not indexed.
 * @param {string[]} props.scope     Post types, or taxonomies, whose keys can be picked.
 * @param {number}   props.total     Number of keys in all.
 * @param {Object[]} props.sources   Where named fields come from, with their count.
 * @param {Object}   props.labels    Post type or taxonomy labels by name.
 * @param {Function} props.onAdd     Called with a key to add.
 * @param {Function} props.onRemove  Called with a key name to remove.
 * @param {string}   props.emptyNote Shown when no key is selected.
 * @return {Element} The section's body.
 */
const MetaKeys = ( {
	kind,
	keys,
	missed,
	scope,
	total,
	sources = [],
	labels,
	onAdd,
	onRemove,
	emptyNote,
} ) => (
	<>
		{ missed.length > 0 && (
			<div className="ms-suggest">
				<span className="ms-suggest__title">
					{ kind === 'term'
						? __(
								'Found in your term queries, served by MySQL because they are not indexed',
								'meiliscout'
						  )
						: __(
								'Found in your queries, served by MySQL because they are not indexed',
								'meiliscout'
						  ) }
				</span>
				<div className="ms-chips">
					{ missed.map( ( key ) => (
						<button
							key={ key }
							type="button"
							className="ms-button ms-button--small"
							onClick={ () => onAdd( { key, type: null } ) }
							aria-label={ sprintf(
								/* translators: %s: a meta key */
								__( 'Index %s', 'meiliscout' ),
								key
							) }
						>
							<span className="ms-mono">{ key }</span>
							<span aria-hidden="true">+</span>
						</button>
					) ) }
				</div>
			</div>
		) }

		{ keys.length > 0 ? (
			<div className="ms-table-wrap" style={ { padding: '8px 0' } }>
				<table className="ms-table" style={ { minWidth: 560 } }>
					<thead>
						<tr>
							<th scope="col">{ __( 'Field', 'meiliscout' ) }</th>
							<th scope="col">
								{ __( 'Detected type', 'meiliscout' ) }
							</th>
							<th scope="col">
								{ __( 'Found on', 'meiliscout' ) }
							</th>
							<th scope="col">
								<span className="ms-visually-hidden">
									{ __( 'Actions', 'meiliscout' ) }
								</span>
							</th>
						</tr>
					</thead>
					<tbody>
						{ keys.map( ( key ) => (
							<tr key={ key.key }>
								<td>
									{ key.label ? (
										<>
											<strong>{ key.label }</strong>
											<br />
											<span className="ms-mono ms-table__muted">
												{ key.key }
											</span>
										</>
									) : (
										<span className="ms-mono">
											{ key.key }
										</span>
									) }
									{ key.group && (
										<span
											className="ms-pill ms-pill--outline"
											style={ { marginLeft: 8 } }
										>
											{ key.group }
										</span>
									) }
								</td>
								<td>
									{ key.type
										? TYPE_LABELS[ key.type ]
										: __( 'After saving', 'meiliscout' ) }
								</td>
								<td className="ms-table__muted">
									{ key.type ? presence( key, labels ) : '—' }
								</td>
								<td className="ms-table__right">
									<button
										type="button"
										className="ms-button ms-button--small"
										onClick={ () => onRemove( key.key ) }
										aria-label={ sprintf(
											/* translators: %s: a field name or meta key */
											__( 'Remove %s', 'meiliscout' ),
											key.label ?? key.key
										) }
									>
										{ __( 'Remove', 'meiliscout' ) }
									</button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
		) : (
			<p className="ms-inline-note" style={ { margin: '16px 24px' } }>
				{ emptyNote }
			</p>
		) }

		<MetaKeyPicker
			kind={ kind }
			scope={ scope }
			exclude={ keys.map( ( key ) => key.key ) }
			total={ total }
			sources={ sources }
			labels={ labels }
			onAdd={ onAdd }
		/>
	</>
);

const Content = ( { refreshOverview } ) => {
	const resource = useResource( '/content' );
	const { data } = resource;
	const [ draft, setDraft ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ toast, showToast ] = useToast();

	useEffect( () => {
		if ( data ) {
			setDraft( fromData( data ) );
		}
	}, [ data ] );

	const saved = useMemo( () => ( data ? fromData( data ) : null ), [ data ] );
	const labels = useMemo(
		() =>
			Object.fromEntries(
				( data?.post_types ?? [] ).map( ( type ) => [
					type.name,
					type.label,
				] )
			),
		[ data ]
	);
	const taxonomyLabels = useMemo(
		() =>
			Object.fromEntries(
				( data?.taxonomies ?? [] ).map( ( taxonomy ) => [
					taxonomy.name,
					taxonomy.label,
				] )
			),
		[ data ]
	);

	if ( resource.error && ! data ) {
		return (
			<div className="ms-wrap ms-page">
				<Banner tone="error" icon="alert" title={ resource.error } />
			</div>
		);
	}

	if ( ! data || ! draft ) {
		return (
			<div className="ms-wrap ms-page" aria-busy="true">
				<Skeleton height={ 280 } />
				<Skeleton height={ 200 } />
			</div>
		);
	}

	const dirty = ! sameSelection( draft, saved );
	const update = ( changes ) => setDraft( { ...draft, ...changes } );
	const selectedKeys = draft.meta_keys.map( ( key ) => key.key );
	const missed = data.missed_meta_keys.filter(
		( key ) => ! selectedKeys.includes( key )
	);
	const selectedTermKeys = draft.term_meta_keys.map( ( key ) => key.key );
	const missedTermKeys = data.missed_term_meta_keys.filter(
		( key ) => ! selectedTermKeys.includes( key )
	);

	// What the custom fields are called, where a plugin named them
	const names = Object.fromEntries(
		draft.meta_keys
			.filter( ( key ) => key.label )
			.map( ( key ) => [ key.key, key.label ] )
	);

	// The meta keys being added can be searched too
	const available = [
		...data.searchable.available.filter(
			( field ) => ! field.startsWith( 'metas.' )
		),
		...selectedKeys.map( ( key ) => 'metas.' + key ),
	];

	const addKey = ( key ) =>
		! selectedKeys.includes( key.key ) &&
		update( { meta_keys: [ ...draft.meta_keys, key ] } );

	const removeKey = ( key ) =>
		update( {
			meta_keys: draft.meta_keys.filter( ( item ) => item.key !== key ),
			searchable:
				draft.searchable?.filter(
					( field ) => field !== 'metas.' + key
				) ?? null,
		} );

	const addTermKey = ( key ) =>
		! selectedTermKeys.includes( key.key ) &&
		update( { term_meta_keys: [ ...draft.term_meta_keys, key ] } );

	const removeTermKey = ( key ) =>
		update( {
			term_meta_keys: draft.term_meta_keys.filter(
				( item ) => item.key !== key
			),
		} );

	const save = () => {
		setSaving( true );
		post( '/content', {
			post_types: draft.post_types,
			taxonomies: draft.taxonomies,
			meta_keys: selectedKeys,
			term_meta_keys: selectedTermKeys,
			weights: draft.weights,
			index_private: draft.index_private,
		} )
			.then( ( result ) => {
				resource.setData( result );
				refreshOverview();
				showToast(
					__(
						'Saved. A full indexation applies the changes.',
						'meiliscout'
					)
				);
			} )
			.catch( ( error ) => showToast( errorMessage( error ), 'error' ) )
			.finally( () => setSaving( false ) );
	};

	return (
		<>
			{ toast }
			<div className="ms-wrap ms-page ms-page--with-side ms-page--with-bar">
				<nav
					className="ms-side"
					aria-label={ __(
						'Sections of the selection',
						'meiliscout'
					) }
				>
					<a href={ href( 'content', 'types' ) }>
						{ __( 'Post types', 'meiliscout' ) }
					</a>
					<a href={ href( 'content', 'taxonomies' ) }>
						{ __( 'Taxonomies', 'meiliscout' ) }
					</a>
					<a href={ href( 'content', 'fields' ) }>
						{ __( 'Custom fields', 'meiliscout' ) }
					</a>
					<a href={ href( 'content', 'term-fields' ) }>
						{ __( 'Term fields', 'meiliscout' ) }
					</a>
					<a href={ href( 'content', 'relevance' ) }>
						{ __( 'Search weights', 'meiliscout' ) }
					</a>
				</nav>

				<div className="ms-main">
					{ data.needs_indexation && ! dirty && (
						<Banner
							title={ __( 'Not applied yet', 'meiliscout' ) }
							actions={
								<a
									className="ms-button ms-button--primary"
									href={ href( 'indexation' ) }
								>
									{ __( 'Run the indexation', 'meiliscout' ) }
								</a>
							}
						>
							<p>
								{ __(
									'The selection changed since the last full indexation.',
									'meiliscout'
								) }
							</p>
						</Banner>
					) }

					<section
						id="types"
						className="ms-card"
						aria-labelledby="ms-types-title"
					>
						<div className="ms-card__head">
							<h2 id="ms-types-title">
								{ __( 'Post types', 'meiliscout' ) }
							</h2>
							<p>
								{ draft.index_private
									? __(
											'Published and private content is sent. Password-protected content is sent without its text.',
											'meiliscout'
									  )
									: __(
											'Only published content is sent. Password-protected content is sent without its text.',
											'meiliscout'
									  ) }
							</p>
						</div>
						<SwitchList
							items={ data.post_types }
							selected={ draft.post_types }
							onToggle={ ( name, on ) =>
								update( {
									post_types: toggled(
										draft.post_types,
										name,
										on
									),
								} )
							}
							meta={ ( type ) =>
								type.name === 'attachment'
									? sprintf(
											/* translators: %s: number of media files */
											_n(
												'%s file',
												'%s files',
												type.count,
												'meiliscout'
											),
											number( type.count )
									  )
									: sprintf(
											/* translators: %s: number of published posts */
											_n(
												'%s published',
												'%s published',
												type.count,
												'meiliscout'
											),
											number( type.count )
									  )
							}
						/>
						<div className="ms-row">
							<Switch
								checked={ draft.index_private }
								onChange={ ( on ) =>
									update( { index_private: on } )
								}
								label={ __(
									'Index private content',
									'meiliscout'
								) }
							/>
							<div className="ms-row__label ms-row__label--text">
								<strong>
									{ __(
										'Index private content',
										'meiliscout'
									) }
								</strong>
								<span>
									{ __(
										'Logged-in users then get the private content they may read from Meilisearch. Off, their queries run on MySQL when there is private content.',
										'meiliscout'
									) }
								</span>
							</div>
						</div>
						{ draft.index_private && (
							<Banner
								icon="alert"
								title={ __(
									'What indexing private content means',
									'meiliscout'
								) }
							>
								<ul className="ms-list">
									<li>
										{ __(
											'Private content and its indexed fields are stored in Meilisearch: anyone with a key that can search these indexes can read them. Never use such a key in the browser, and check who can reach the instance (Meilisearch Cloud dashboard, other sites sharing it).',
											'meiliscout'
										) }
									</li>
									<li>
										{ __(
											'MeiliScout filters each query by the WordPress permissions of the user (read_private_posts, or their own content). A query sent to Meilisearch directly is not filtered.',
											'meiliscout'
										) }
									</li>
								</ul>
							</Banner>
						) }
					</section>

					<section
						id="taxonomies"
						className="ms-card"
						aria-labelledby="ms-tax-title"
					>
						<div className="ms-card__head">
							<h2 id="ms-tax-title">
								{ __( 'Taxonomies', 'meiliscout' ) }
							</h2>
							<p>
								{ __(
									'The terms of indexed content can always be filtered on. Turn a taxonomy on to index its terms themselves too (term pages, autocompletion).',
									'meiliscout'
								) }
							</p>
						</div>
						<SwitchList
							items={ data.taxonomies }
							selected={ draft.taxonomies }
							onToggle={ ( name, on ) =>
								update( {
									taxonomies: toggled(
										draft.taxonomies,
										name,
										on
									),
								} )
							}
							meta={ ( taxonomy ) =>
								sprintf(
									/* translators: %s: number of terms */
									_n(
										'%s term',
										'%s terms',
										taxonomy.count,
										'meiliscout'
									),
									number( taxonomy.count )
								)
							}
						/>
					</section>

					<section
						id="fields"
						className="ms-card"
						aria-labelledby="ms-fields-title"
					>
						<div className="ms-card__head">
							<h2 id="ms-fields-title">
								{ __( 'Custom fields', 'meiliscout' ) }
							</h2>
							<p>
								{ createInterpolateElement(
									__(
										'The fields added here are sent with each content, and can be used in a <code>meta_query</code> and to sort.',
										'meiliscout'
									),
									{ code: <code /> }
								) }
							</p>
						</div>

						<MetaKeys
							kind="post"
							keys={ draft.meta_keys }
							missed={ missed }
							scope={ draft.post_types }
							total={ data.meta_key_total }
							sources={ data.field_sources ?? [] }
							labels={ labels }
							onAdd={ addKey }
							onRemove={ removeKey }
							emptyNote={ __(
								'No field selected: every meta of the content is sent, private ones included. Select the ones your queries need.',
								'meiliscout'
							) }
						/>
					</section>

					<section
						id="term-fields"
						className="ms-card"
						aria-labelledby="ms-term-fields-title"
					>
						<div className="ms-card__head">
							<h2 id="ms-term-fields-title">
								{ __( 'Term fields', 'meiliscout' ) }
							</h2>
							<p>
								{ createInterpolateElement(
									__(
										'The term metas added here are sent with each term of the indexed taxonomies, and can be used in the <code>meta_query</code> of a term query and to sort it.',
										'meiliscout'
									),
									{ code: <code /> }
								) }
							</p>
						</div>
						{ draft.taxonomies.length > 0 ? (
							<MetaKeys
								kind="term"
								keys={ draft.term_meta_keys }
								missed={ missedTermKeys }
								scope={ draft.taxonomies }
								total={ data.term_meta_key_total }
								labels={ taxonomyLabels }
								onAdd={ addTermKey }
								onRemove={ removeTermKey }
								emptyNote={ __(
									'No field selected: every meta of the terms is sent. Select the ones your term queries need.',
									'meiliscout'
								) }
							/>
						) : (
							<p
								className="ms-inline-note"
								style={ { margin: '16px 24px' } }
							>
								{ __(
									'Turn a taxonomy on to index its terms and their fields.',
									'meiliscout'
								) }
							</p>
						) }
					</section>

					<section
						id="relevance"
						className="ms-card"
						aria-labelledby="ms-relevance-title"
					>
						<div className="ms-card__head">
							<h2 id="ms-relevance-title">
								{ __( 'Search weights', 'meiliscout' ) }
							</h2>
							<p>
								{ __(
									'How much each field counts in a search, per post type: a field with a higher weight ranks its matches first, and 0 leaves a field out.',
									'meiliscout'
								) }
							</p>
						</div>
						<Weights
							types={ draft.post_types }
							labels={ labels }
							weights={ draft.weights }
							defaults={ data.weights.defaults }
							available={ available }
							names={ names }
							onChange={ ( weights ) => update( { weights } ) }
						/>
					</section>
				</div>
			</div>

			{ dirty && (
				<div className="ms-savebar">
					<div className="ms-wrap ms-savebar__inner">
						<span>
							{ __(
								'Unsaved changes · a full indexation will be needed to apply them.',
								'meiliscout'
							) }
						</span>
						<div className="ms-savebar__actions">
							<button
								type="button"
								className="ms-button ms-button--ghost"
								onClick={ () => setDraft( saved ) }
								disabled={ saving }
							>
								{ __( 'Cancel', 'meiliscout' ) }
							</button>
							<button
								type="button"
								className="ms-button ms-button--primary"
								onClick={ save }
								disabled={ saving }
							>
								{ saving
									? __( 'Saving…', 'meiliscout' )
									: __( 'Save', 'meiliscout' ) }
							</button>
						</div>
					</div>
				</div>
			) }
		</>
	);
};

export default Content;
