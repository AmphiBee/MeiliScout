import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { get, errorMessage } from '../api';
import { Banner, Pill, Skeleton, useResource } from '../components';
import { href } from '../routes';

const LEVELS = {
	error: { tone: 'error', label: __( 'Error', 'meiliscout' ) },
	warning: { tone: 'warning', label: __( 'Warning', 'meiliscout' ) },
	info: { tone: 'info', label: __( 'Note', 'meiliscout' ) },
};

const TRANSPORTS = {
	fragment: __( 'HTML fragments', 'meiliscout' ),
	client: __( 'Cards built in the browser', 'meiliscout' ),
	page: __( 'Whole pages', 'meiliscout' ),
};

/**
 * The worst level of a listing's checks.
 *
 * @param {Object} listing
 * @return {string|null} error, warning, info or null.
 */
const worst = ( listing ) =>
	[ 'error', 'warning', 'info' ].find( ( level ) =>
		listing.checks.some( ( check ) => check.level === level )
	) ?? null;

const StatusPill = ( { listing } ) => {
	const level = worst( listing );
	const count = listing.checks.filter(
		( check ) => check.level === level
	).length;

	if ( level === 'error' ) {
		return (
			<Pill tone="error">
				{ sprintf(
					/* translators: %d: number of errors */
					_n( '%d error', '%d errors', count, 'meiliscout' ),
					count
				) }
			</Pill>
		);
	}
	if ( level === 'warning' ) {
		return (
			<Pill tone="warning">
				{ sprintf(
					/* translators: %d: number of warnings */
					_n( '%d warning', '%d warnings', count, 'meiliscout' ),
					count
				) }
			</Pill>
		);
	}

	return <Pill tone="success">{ __( 'Ready', 'meiliscout' ) }</Pill>;
};

const facetType = ( facet ) => {
	if ( facet.type === 'range' ) {
		return __( 'Range', 'meiliscout' );
	}
	if ( facet.type === 'boolean' ) {
		return __( 'Yes / no', 'meiliscout' );
	}
	const logic =
		facet.logic === 'and'
			? __( 'all of (AND)', 'meiliscout' )
			: __( 'any of (OR)', 'meiliscout' );

	return facet.hierarchical
		? sprintf(
				/* translators: %s: any of (OR), all of (AND) */
				__( 'Tree, %s', 'meiliscout' ),
				logic
		  )
		: sprintf(
				/* translators: %s: any of (OR), all of (AND) */
				__( 'List, %s', 'meiliscout' ),
				logic
		  );
};

const Prefixes = ( { facet } ) => {
	const entries = Object.entries( facet.path ?? {} );

	if ( ! entries.length ) {
		return <span className="ms-table__muted">—</span>;
	}

	// One prefix for every language: no need to name them
	if ( new Set( entries.map( ( [ , prefix ] ) => prefix ) ).size === 1 ) {
		return <code>{ entries[ 0 ][ 1 ] }-…</code>;
	}

	return entries.map( ( [ language, prefix ], index ) => (
		<span key={ language }>
			{ index > 0 && ', ' }
			<code>{ prefix }-…</code>{ ' ' }
			<span className="ms-table__muted">{ language }</span>
		</span>
	) );
};

const Checks = ( { checks } ) =>
	checks.length > 0 && (
		<ul className="ms-listing-checks">
			{ checks.map( ( check, index ) => (
				<li key={ index }>
					<Pill tone={ LEVELS[ check.level ].tone }>
						{ LEVELS[ check.level ].label }
					</Pill>
					<span>{ check.message }</span>
				</li>
			) ) }
		</ul>
	);

const Parity = ( { listing, languages } ) => {
	const [ language, setLanguage ] = useState( languages[ 0 ]?.code ?? '' );
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ loading, setLoading ] = useState( false );

	const run = () => {
		setLoading( true );
		setError( null );
		get( `/listings/${ listing.id }/parity`, { lang: language } )
			.then( setResult )
			.catch( ( reason ) => {
				setResult( null );
				setError( errorMessage( reason ) );
			} )
			.finally( () => setLoading( false ) );
	};

	const differing = result?.rows.filter( ( row ) => ! row.ok ) ?? [];

	return (
		<div className="ms-listing-parity">
			<div className="ms-field__row">
				{ languages.length > 1 && (
					<>
						<label
							htmlFor={ `ms-parity-${ listing.id }` }
							className="ms-visually-hidden"
						>
							{ __( 'Language', 'meiliscout' ) }
						</label>
						<select
							id={ `ms-parity-${ listing.id }` }
							className="ms-input"
							value={ language }
							onChange={ ( event ) => {
								setLanguage( event.target.value );
								setResult( null );
							} }
						>
							{ languages.map( ( item ) => (
								<option key={ item.code } value={ item.code }>
									{ item.code }
								</option>
							) ) }
						</select>
					</>
				) }
				<button
					type="button"
					className="ms-button"
					onClick={ run }
					disabled={ loading }
				>
					{ loading
						? __( 'Counting…', 'meiliscout' )
						: __( 'Compare the counts with MySQL', 'meiliscout' ) }
				</button>
			</div>
			<div aria-live="polite">
				{ error && (
					<Banner tone="error" icon="alert">
						<p>{ error }</p>
					</Banner>
				) }
				{ result && (
					<>
						<p>
							{ result.diffs === 0 ? (
								<Pill tone="success">
									{ __( 'Same counts', 'meiliscout' ) }
								</Pill>
							) : (
								<Pill tone="error">
									{ sprintf(
										/* translators: %d: number of differences */
										_n(
											'%d difference',
											'%d differences',
											result.diffs,
											'meiliscout'
										),
										result.diffs
									) }
								</Pill>
							) }{ ' ' }
							{ sprintf(
								/* translators: 1: total shown, 2: total on MySQL, 3: number of values */
								__(
									'Total %1$d (MySQL %2$d), %3$d values compared.',
									'meiliscout'
								),
								result.total.shown,
								result.total.mysql,
								result.rows.length
							) }
						</p>
						{ ! result.served && (
							<p className="ms-field__help">
								{ sprintf(
									/* translators: %s: why MySQL served the query */
									__(
										'MySQL served the posts’ query (%s): only the facets’ counts come from Meilisearch.',
										'meiliscout'
									),
									result.reason
								) }
							</p>
						) }
						{ result.error && (
							<p className="ms-field__help">{ result.error }</p>
						) }
						{ differing.length > 0 && (
							<div className="ms-table-wrap">
								<table className="ms-table">
									<thead>
										<tr>
											<th scope="col">
												{ __( 'Facet', 'meiliscout' ) }
											</th>
											<th scope="col">
												{ __( 'Value', 'meiliscout' ) }
											</th>
											<th scope="col">
												{ __( 'Shown', 'meiliscout' ) }
											</th>
											<th scope="col">MySQL</th>
										</tr>
									</thead>
									<tbody>
										{ differing.map( ( row ) => (
											<tr
												key={
													row.facet + '=' + row.value
												}
											>
												<td>{ row.facet }</td>
												<td>{ row.label }</td>
												<td>{ row.shown }</td>
												<td>{ row.mysql }</td>
											</tr>
										) ) }
									</tbody>
								</table>
							</div>
						) }
					</>
				) }
			</div>
		</div>
	);
};

const Line = ( { label, children } ) => (
	<div className="ms-row">
		<div className="ms-row__label ms-row__label--text">
			<strong>{ label }</strong>
		</div>
		<div className="ms-listing__value">{ children }</div>
	</div>
);

const ListingCard = ( { listing, languages } ) => {
	const titleId = `ms-listing-${ listing.id }`;

	return (
		<section className="ms-card" aria-labelledby={ titleId }>
			<div className="ms-card__head ms-card__head--row">
				<div>
					<h2 id={ titleId }>
						<code>{ listing.id }</code>
					</h2>
					<p>
						{ listing.source === 'block'
							? __( 'Listing block', 'meiliscout' )
							: __( 'Declared in PHP', 'meiliscout' ) }
						{ listing.post && (
							<>
								{ ' · ' }
								{ listing.post.edit ? (
									<a href={ listing.post.edit }>
										{ listing.post.title ||
											'#' + listing.post.id }
									</a>
								) : (
									listing.post.title
								) }
							</>
						) }
						{ listing.valid && (
							<>
								{ ' · ' }
								{ listing.post_types
									.map( ( type ) => type.label )
									.join( ', ' ) }
								{ ' · ' }
								{ sprintf(
									/* translators: %d: posts per page */
									__( '%d per page', 'meiliscout' ),
									listing.per_page
								) }
							</>
						) }
					</p>
				</div>
				<StatusPill listing={ listing } />
			</div>

			<Checks checks={ listing.checks } />

			{ listing.valid && (
				<>
					<div className="ms-listing__lines">
						<Line label={ __( 'Address', 'meiliscout' ) }>
							{ listing.route === null && (
								<span className="ms-table__muted">
									{ __(
										'Wherever a template or a block prints it',
										'meiliscout'
									) }
								</span>
							) }
							{ listing.route !== null && (
								<ul className="ms-listing-urls">
									{ listing.routes.map( ( route ) => (
										<li key={ route.language }>
											{ route.language && (
												<Pill tone="outline">
													{ route.language }
												</Pill>
											) }
											{ route.url ? (
												<a
													href={ route.url }
													target="_blank"
													rel="noreferrer"
												>
													{ route.url }
													<span className="ms-visually-hidden">
														{ __(
															'(opens in a new tab)',
															'meiliscout'
														) }
													</span>
												</a>
											) : (
												<span className="ms-table__muted">
													{ __(
														'No page in this language',
														'meiliscout'
													) }
												</span>
											) }
										</li>
									) ) }
								</ul>
							) }
						</Line>
						<Line label={ __( 'Results', 'meiliscout' ) }>
							{ TRANSPORTS[ listing.transport ] }
							{ listing.apply === 'button' &&
								' · ' +
									__(
										'Filters applied with a button',
										'meiliscout'
									) }
							{ listing.personalised &&
								' · ' +
									__(
										'Personalised cards (not cached)',
										'meiliscout'
									) }
						</Line>
						<Line label={ __( 'Sorts', 'meiliscout' ) }>
							{ listing.sorts.map( ( sort, index ) => (
								<span key={ sort.key }>
									{ index > 0 && ', ' }
									{ sort.label } <code>{ sort.key }</code>
									{ sort.key === listing.default_sort && (
										<span className="ms-table__muted">
											{ ' ' }
											{ __( '(default)', 'meiliscout' ) }
										</span>
									) }
								</span>
							) ) }
						</Line>
						<Line label={ __( 'Parameters', 'meiliscout' ) }>
							{ listing.parameters.map( ( parameter, index ) => (
								<span key={ parameter.name }>
									{ index > 0 && ' ' }
									<code
										className={
											parameter.reserved
												? 'ms-listing__reserved'
												: undefined
										}
										title={ parameter.role }
									>
										{ parameter.name }
									</code>
								</span>
							) ) }
						</Line>
						<Line label={ __( 'Search engines', 'meiliscout' ) }>
							{ listing.seo.enabled ? (
								<>
									{ sprintf(
										/* translators: 1: number of facets, 2: number of results */
										__(
											'Views of up to %1$d facets of the path, one value each, %2$d results or more, are indexed.',
											'meiliscout'
										),
										listing.seo.max_depth,
										listing.seo.min_results
									) }{ ' ' }
									<a href={ href( 'seo-rules' ) }>
										{ sprintf(
											/* translators: %d: number of SEO rules */
											_n(
												'%d SEO rule',
												'%d SEO rules',
												listing.seo.rules,
												'meiliscout'
											),
											listing.seo.rules
										) }
									</a>
								</>
							) : (
								__(
									'Off (seo: false): the SEO plugin decides.',
									'meiliscout'
								)
							) }
						</Line>
					</div>

					<div className="ms-table-wrap">
						<table className="ms-table">
							<caption className="ms-visually-hidden">
								{ sprintf(
									/* translators: %s: a listing */
									__( 'Facets of %s', 'meiliscout' ),
									listing.id
								) }
							</caption>
							<thead>
								<tr>
									<th scope="col">
										{ __( 'Facet', 'meiliscout' ) }
									</th>
									<th scope="col">
										{ __( 'Source', 'meiliscout' ) }
									</th>
									<th scope="col">
										{ __( 'Type', 'meiliscout' ) }
									</th>
									<th scope="col">
										{ __( 'Parameter', 'meiliscout' ) }
									</th>
									<th scope="col">
										{ __( 'In the path', 'meiliscout' ) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ listing.facets.map( ( facet ) => (
									<tr key={ facet.key }>
										<td>
											{ facet.label }{ ' ' }
											<span className="ms-table__muted">
												{ facet.key }
											</span>
										</td>
										<td>
											<code>{ facet.source }</code>
										</td>
										<td>
											{ facetType( facet ) }
											{ facet.search && (
												<span className="ms-table__muted">
													{ ' · ' }
													{ __(
														'with a search',
														'meiliscout'
													) }
												</span>
											) }
										</td>
										<td>
											{ Object.keys( facet.path ?? {} )
												.length ? (
												<span className="ms-table__muted">
													—
												</span>
											) : (
												<code>{ facet.param }</code>
											) }
										</td>
										<td>
											<Prefixes facet={ facet } />
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>

					<div className="ms-card__foot">
						<Parity listing={ listing } languages={ languages } />
					</div>
				</>
			) }
		</section>
	);
};

const Listings = () => {
	const resource = useResource( '/listings' );
	const data = resource.data;

	if ( resource.error ) {
		return (
			<div className="ms-wrap ms-page">
				<Banner tone="error" icon="alert">
					<p>{ resource.error }</p>
				</Banner>
			</div>
		);
	}

	if ( ! data ) {
		return (
			<div className="ms-wrap ms-page">
				<Skeleton height={ 240 } />
			</div>
		);
	}

	const errors = data.listings.filter(
		( listing ) => worst( listing ) === 'error'
	).length;

	return (
		<div className="ms-wrap ms-page" style={ { gap: 20 } }>
			<section className="ms-card" aria-labelledby="ms-listings-title">
				<div className="ms-card__head ms-card__head--row">
					<div>
						<h2 id="ms-listings-title">
							{ __( 'Listings', 'meiliscout' ) }
						</h2>
						<p>
							{ __(
								'The filterable listings the site declares, in PHP or with the Listing block, and what may keep them from working.',
								'meiliscout'
							) }
						</p>
					</div>
					<button
						type="button"
						className="ms-button"
						onClick={ resource.reload }
						disabled={ resource.loading }
					>
						{ __( 'Check again', 'meiliscout' ) }
					</button>
				</div>
				<div className="ms-stats ms-stats--four">
					<div className="ms-stat">
						<span className="ms-stat__label">
							{ __( 'Listings', 'meiliscout' ) }
						</span>
						<span className="ms-stat__value">
							{ data.listings.length }
						</span>
					</div>
					<div className="ms-stat">
						<span className="ms-stat__label">
							{ __( 'Languages', 'meiliscout' ) }
						</span>
						<span className="ms-stat__value ms-stat__value--small">
							{ data.languages.length
								? data.languages
										.map( ( language ) => language.code )
										.join( ', ' )
								: __( 'One', 'meiliscout' ) }
						</span>
					</div>
					<div className="ms-stat">
						<span className="ms-stat__label">
							{ __( 'SEO written by', 'meiliscout' ) }
						</span>
						<span className="ms-stat__value ms-stat__value--small">
							{ data.seo_adapter }
						</span>
					</div>
					<div className="ms-stat">
						<span className="ms-stat__label">
							{ __( 'Sitemap', 'meiliscout' ) }
						</span>
						<span className="ms-stat__value ms-stat__value--small">
							<a
								href={ data.sitemap.url }
								target="_blank"
								rel="noreferrer"
							>
								{ sprintf(
									/* translators: %d: number of views */
									_n(
										'%d view',
										'%d views',
										data.sitemap.views,
										'meiliscout'
									),
									data.sitemap.views
								) }
							</a>
						</span>
					</div>
				</div>
				{ errors > 0 && (
					<div className="ms-card__body">
						<Banner tone="error" icon="alert">
							<p>
								{ sprintf(
									/* translators: %d: number of listings */
									_n(
										'%d listing has errors: it is not served, or some of its views are not.',
										'%d listings have errors: they are not served, or some of their views are not.',
										errors,
										'meiliscout'
									),
									errors
								) }
							</p>
						</Banner>
					</div>
				) }
				{ ! data.listings.length && (
					<p className="ms-empty">
						{ __(
							'No listing is declared: add a Listing block to a page, or call meiliscout_register_listing().',
							'meiliscout'
						) }
					</p>
				) }
			</section>

			{ data.listings.map( ( listing ) => (
				<ListingCard
					key={ listing.id }
					listing={ listing }
					languages={ data.languages }
				/>
			) ) }
		</div>
	);
};

export default Listings;
