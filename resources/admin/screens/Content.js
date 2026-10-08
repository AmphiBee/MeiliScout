import {
	createInterpolateElement,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { get, post, errorMessage } from '../api';
import {
	Banner,
	Icon,
	Skeleton,
	Switch,
	useResource,
	useToast,
} from '../components';
import { number } from '../format';
import { href } from '../routes';

const TYPE_LABELS = {
	number: __( 'Number', 'meiliscout' ),
	date: __( 'Date', 'meiliscout' ),
	boolean: __( 'Yes / no', 'meiliscout' ),
	list: __( 'List', 'meiliscout' ),
	text: __( 'Text', 'meiliscout' ),
	empty: __( 'Always empty', 'meiliscout' ),
};

const FIELD_LABELS = {
	post_title: __( 'Title', 'meiliscout' ),
	'terms.name': __( 'Terms', 'meiliscout' ),
	post_excerpt: __( 'Excerpt', 'meiliscout' ),
	content_text: __( 'Content, without markup', 'meiliscout' ),
	post_content: __( 'Content, raw HTML', 'meiliscout' ),
	post_name: __( 'Slug', 'meiliscout' ),
};

const fieldLabel = ( field ) =>
	FIELD_LABELS[ field ] ??
	sprintf(
		/* translators: %s: a meta key */
		__( 'Field %s', 'meiliscout' ),
		field.replace( /^metas\./, '' )
	);

const fromData = ( data ) => ( {
	post_types: data.post_types
		.filter( ( type ) => type.selected )
		.map( ( type ) => type.name ),
	taxonomies: data.taxonomies
		.filter( ( taxonomy ) => taxonomy.selected )
		.map( ( taxonomy ) => taxonomy.name ),
	meta_keys: data.meta_keys,
	searchable: data.searchable.configured,
} );

const sameSelection = ( a, b ) =>
	JSON.stringify( {
		...a,
		meta_keys: a.meta_keys.map( ( key ) => key.key ),
	} ) ===
	JSON.stringify( {
		...b,
		meta_keys: b.meta_keys.map( ( key ) => key.key ),
	} );

const toggled = ( list, value, on ) =>
	on ? [ ...list, value ] : list.filter( ( item ) => item !== value );

/**
 * Where a meta key is found: "72 projects, 18 posts".
 *
 * @param {Object} postTypes Counts by post type.
 * @param {Object} labels    Post type labels by name.
 * @return {string} The description.
 */
const presence = ( postTypes, labels ) => {
	const parts = Object.entries( postTypes ?? {} ).map(
		( [ type, count ] ) =>
			number( count ) +
			' ' +
			( labels[ type ] ?? type ).toLocaleLowerCase()
	);

	return parts.length
		? parts.join( ', ' )
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
 * A search field listing the meta keys of the selected post types.
 *
 * @param {Object}   props
 * @param {string[]} props.postTypes Post types whose keys are listed.
 * @param {string[]} props.exclude   Keys already selected.
 * @param {number}   props.total     Number of keys in all.
 * @param {Object}   props.labels    Post type labels by name.
 * @param {Function} props.onAdd     Called with the key picked.
 * @return {Element} The field.
 */
const MetaKeyPicker = ( { postTypes, exclude, total, labels, onAdd } ) => {
	const [ query, setQuery ] = useState( '' );
	const [ open, setOpen ] = useState( false );
	const [ results, setResults ] = useState( [] );
	const [ active, setActive ] = useState( 0 );
	const listId = 'ms-meta-key-options';
	const timer = useRef();

	useEffect( () => {
		if ( ! open ) {
			return;
		}
		clearTimeout( timer.current );
		timer.current = setTimeout( () => {
			get( '/meta-keys', {
				search: query,
				post_types: postTypes,
				exclude,
			} )
				.then( ( response ) => {
					setResults( response.keys );
					setActive( 0 );
				} )
				.catch( () => setResults( [] ) );
		}, 200 );

		return () => clearTimeout( timer.current );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ query, open, postTypes.join(), exclude.join() ] );

	const add = ( key ) => {
		onAdd( key );
		setQuery( '' );
		setOpen( false );
	};

	const typed = query.trim();
	const addTyped = () =>
		typed && add( { key: typed, type: null, posts: 0, post_types: {} } );

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

	return (
		<div className="ms-add">
			<label htmlFor="ms-meta-key" className="ms-field__label">
				{ __( 'Add a field', 'meiliscout' ) }
			</label>
			<div className="ms-field__row ms-combobox">
				<div>
					<input
						id="ms-meta-key"
						className="ms-input"
						type="text"
						role="combobox"
						autoComplete="off"
						aria-expanded={ open && results.length > 0 }
						aria-controls={ listId }
						aria-autocomplete="list"
						aria-activedescendant={
							open && results[ active ]
								? 'ms-meta-key-' + active
								: undefined
						}
						value={ query }
						placeholder={ sprintf(
							/* translators: %s: number of meta keys */
							_n(
								'Search the %s key of your content',
								'Search the %s keys of your content',
								total,
								'meiliscout'
							),
							number( total )
						) }
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
					{ open && results.length > 0 && (
						<ul
							className="ms-combobox__list"
							id={ listId }
							role="listbox"
							aria-label={ __( 'Meta keys', 'meiliscout' ) }
						>
							{ results.map( ( result, index ) => (
								<li
									key={ result.key }
									id={ 'ms-meta-key-' + index }
									role="option"
									aria-selected={ index === active }
									className="ms-combobox__option"
									onMouseDown={ ( event ) => {
										event.preventDefault();
										add( result );
									} }
								>
									<span className="ms-mono">
										{ result.key }
									</span>
									<small>
										{ TYPE_LABELS[ result.type ] }
									</small>
									<small>
										{ presence(
											result.post_types,
											labels
										) }
									</small>
								</li>
							) ) }
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

const Relevance = ( { searchable, available, suggested, onChange } ) => {
	if ( searchable === null ) {
		const order = suggested.filter( ( field ) =>
			available.includes( field )
		);

		return (
			<div className="ms-card__body">
				<p className="ms-inline-note">
					{ createInterpolateElement(
						__(
							'Searches look into every field today, <guid /> and the markup of the blocks included, and a match anywhere counts the same.',
							'meiliscout'
						),
						{ guid: <code>guid</code> }
					) }
				</p>
				<ol className="ms-ranking" style={ { padding: 0 } }>
					{ order.map( ( field, index ) => (
						<li key={ field } style={ { paddingLeft: 0 } }>
							<span className="ms-ranking__rank">
								{ index + 1 }
							</span>
							<span className="ms-ranking__label">
								{ fieldLabel( field ) }
							</span>
							<span className="ms-ranking__field">{ field }</span>
						</li>
					) ) }
				</ol>
				<div>
					<button
						type="button"
						className="ms-button"
						onClick={ () => onChange( order ) }
					>
						{ __( 'Use this order', 'meiliscout' ) }
					</button>
				</div>
			</div>
		);
	}

	const move = ( index, offset ) => {
		const next = [ ...searchable ];
		[ next[ index ], next[ index + offset ] ] = [
			next[ index + offset ],
			next[ index ],
		];
		onChange( next );
	};
	const unused = available.filter(
		( field ) => ! searchable.includes( field )
	);

	return (
		<>
			<ol className="ms-ranking">
				{ searchable.map( ( field, index ) => (
					<li key={ field }>
						<span className="ms-ranking__rank">{ index + 1 }</span>
						<span className="ms-ranking__label">
							{ fieldLabel( field ) }
						</span>
						<span className="ms-ranking__field">{ field }</span>
						<span className="ms-ranking__actions">
							<button
								type="button"
								className="ms-icon-button"
								disabled={ index === 0 }
								onClick={ () => move( index, -1 ) }
								aria-label={ sprintf(
									/* translators: %s: a field name */
									__( 'Move %s up', 'meiliscout' ),
									fieldLabel( field )
								) }
							>
								<Icon name="up" />
							</button>
							<button
								type="button"
								className="ms-icon-button"
								disabled={ index === searchable.length - 1 }
								onClick={ () => move( index, 1 ) }
								aria-label={ sprintf(
									/* translators: %s: a field name */
									__( 'Move %s down', 'meiliscout' ),
									fieldLabel( field )
								) }
							>
								<Icon name="down" />
							</button>
							<button
								type="button"
								className="ms-icon-button"
								disabled={ searchable.length === 1 }
								onClick={ () =>
									onChange(
										searchable.filter(
											( item ) => item !== field
										)
									)
								}
								aria-label={ sprintf(
									/* translators: %s: a field name */
									__( 'Stop searching %s', 'meiliscout' ),
									fieldLabel( field )
								) }
							>
								<Icon name="close" />
							</button>
						</span>
					</li>
				) ) }
			</ol>
			<div
				className="ms-add"
				style={ { flexDirection: 'row', flexWrap: 'wrap', gap: 8 } }
			>
				{ unused.length > 0 && (
					<>
						<label
							htmlFor="ms-add-field"
							className="ms-visually-hidden"
						>
							{ __( 'Search one more field', 'meiliscout' ) }
						</label>
						<select
							id="ms-add-field"
							value=""
							onChange={ ( event ) =>
								event.target.value &&
								onChange( [
									...searchable,
									event.target.value,
								] )
							}
						>
							<option value="">
								{ __( 'Search one more field…', 'meiliscout' ) }
							</option>
							{ unused.map( ( field ) => (
								<option key={ field } value={ field }>
									{ fieldLabel( field ) } ({ field })
								</option>
							) ) }
						</select>
					</>
				) }
				<button
					type="button"
					className="ms-button ms-button--small"
					onClick={ () => onChange( null ) }
				>
					{ __( 'Search every field again', 'meiliscout' ) }
				</button>
			</div>
		</>
	);
};

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

	const save = () => {
		setSaving( true );
		post( '/content', {
			post_types: draft.post_types,
			taxonomies: draft.taxonomies,
			meta_keys: selectedKeys,
			searchable: draft.searchable ?? [],
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
					<a href={ href( 'content', 'relevance' ) }>
						{ __( 'Relevance', 'meiliscout' ) }
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
								{ __(
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
								sprintf(
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

						{ missed.length > 0 && (
							<div className="ms-suggest">
								<span className="ms-suggest__title">
									{ __(
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
											onClick={ () =>
												addKey( {
													key,
													type: null,
													posts: 0,
													post_types: {},
												} )
											}
											aria-label={ sprintf(
												/* translators: %s: a meta key */
												__( 'Index %s', 'meiliscout' ),
												key
											) }
										>
											<span className="ms-mono">
												{ key }
											</span>
											<span aria-hidden="true">+</span>
										</button>
									) ) }
								</div>
							</div>
						) }

						{ draft.meta_keys.length > 0 ? (
							<div
								className="ms-table-wrap"
								style={ { padding: '8px 0' } }
							>
								<table
									className="ms-table"
									style={ { minWidth: 560 } }
								>
									<thead>
										<tr>
											<th scope="col">
												{ __( 'Key', 'meiliscout' ) }
											</th>
											<th scope="col">
												{ __(
													'Detected type',
													'meiliscout'
												) }
											</th>
											<th scope="col">
												{ __(
													'Found on',
													'meiliscout'
												) }
											</th>
											<th scope="col">
												<span className="ms-visually-hidden">
													{ __(
														'Actions',
														'meiliscout'
													) }
												</span>
											</th>
										</tr>
									</thead>
									<tbody>
										{ draft.meta_keys.map( ( key ) => (
											<tr key={ key.key }>
												<td className="ms-mono">
													{ key.key }
												</td>
												<td>
													{ key.type
														? TYPE_LABELS[
																key.type
														  ]
														: __(
																'After saving',
																'meiliscout'
														  ) }
												</td>
												<td className="ms-table__muted">
													{ key.type
														? presence(
																key.post_types,
																labels
														  )
														: '—' }
												</td>
												<td className="ms-table__right">
													<button
														type="button"
														className="ms-button ms-button--small"
														onClick={ () =>
															removeKey( key.key )
														}
														aria-label={ sprintf(
															/* translators: %s: a meta key */
															__(
																'Remove %s',
																'meiliscout'
															),
															key.key
														) }
													>
														{ __(
															'Remove',
															'meiliscout'
														) }
													</button>
												</td>
											</tr>
										) ) }
									</tbody>
								</table>
							</div>
						) : (
							<p
								className="ms-inline-note"
								style={ { margin: '16px 24px' } }
							>
								{ __(
									'No field selected: every meta of the content is sent, private ones included. Select the ones your queries need.',
									'meiliscout'
								) }
							</p>
						) }

						<MetaKeyPicker
							postTypes={ draft.post_types }
							exclude={ selectedKeys }
							total={ data.meta_key_total }
							labels={ labels }
							onAdd={ addKey }
						/>
					</section>

					<section
						id="relevance"
						className="ms-card"
						aria-labelledby="ms-relevance-title"
					>
						<div className="ms-card__head">
							<h2 id="ms-relevance-title">
								{ __( 'Relevance', 'meiliscout' ) }
							</h2>
							<p>
								{ __(
									'The fields a search looks into, from the most to the least important.',
									'meiliscout'
								) }
							</p>
						</div>
						<Relevance
							searchable={ draft.searchable }
							available={ available }
							suggested={ data.searchable.suggested }
							onChange={ ( searchable ) =>
								update( { searchable } )
							}
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
