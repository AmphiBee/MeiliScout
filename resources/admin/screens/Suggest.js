import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { errorMessage, post } from '../api';
import { Banner, Pill, Skeleton, useResource, useToast } from '../components';

const LEVELS = {
	error: { tone: 'error', label: __( 'Error', 'meiliscout' ) },
	warning: { tone: 'warning', label: __( 'Warning', 'meiliscout' ) },
	info: { tone: 'info', label: __( 'Note', 'meiliscout' ) },
};

const TEMPLATES = {
	default: __( 'Default', 'meiliscout' ),
	html: 'HTML',
	blade: 'Blade',
	twig: 'Twig',
	part: __( 'Theme template part', 'meiliscout' ),
	callable: __( 'PHP function', 'meiliscout' ),
	class: __( 'PHP class', 'meiliscout' ),
	js: 'JavaScript',
};

const TRANSPORTS = {
	direct: __( 'Meilisearch, directly from the browser', 'meiliscout' ),
	rest: __( 'Through WordPress', 'meiliscout' ),
};

/**
 * The worst level of a field's checks.
 *
 * @param {Object} suggest
 * @return {string|null} error, warning, info or null.
 */
const worst = ( suggest ) =>
	[ 'error', 'warning', 'info' ].find( ( level ) =>
		suggest.checks.some( ( check ) => check.level === level )
	) ?? null;

const StatusPill = ( { suggest } ) => {
	const level = worst( suggest );
	const count = suggest.checks.filter(
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

const Line = ( { label, children } ) => (
	<div className="ms-row">
		<div className="ms-row__label ms-row__label--text">
			<strong>{ label }</strong>
		</div>
		<div className="ms-listing__value">{ children }</div>
	</div>
);

const SuggestCard = ( { suggest } ) => {
	const titleId = `ms-suggest-${ suggest.id }`;

	return (
		<section className="ms-card" aria-labelledby={ titleId }>
			<div className="ms-card__head ms-card__head--row">
				<div>
					<h2 id={ titleId }>
						<code>{ suggest.id }</code>
					</h2>
					<p>
						{ suggest.source === 'block'
							? __(
									'Search with suggestions block',
									'meiliscout'
							  )
							: __( 'Declared in PHP', 'meiliscout' ) }
						{ suggest.post && (
							<>
								{ ' · ' }
								{ suggest.post.edit ? (
									<a href={ suggest.post.edit }>
										{ suggest.post.title ||
											'#' + suggest.post.id }
									</a>
								) : (
									suggest.post.title
								) }
							</>
						) }
						{ suggest.valid && (
							<>
								{ ' · ' }
								{ TRANSPORTS[ suggest.transport ] }
							</>
						) }
					</p>
				</div>
				<StatusPill suggest={ suggest } />
			</div>

			<Checks checks={ suggest.checks } />

			{ suggest.valid && (
				<div className="ms-listing__lines">
					<Line label={ __( 'Suggests', 'meiliscout' ) }>
						<div className="ms-table-wrap">
							<table className="ms-table">
								<thead>
									<tr>
										<th scope="col">
											{ __( 'Group', 'meiliscout' ) }
										</th>
										<th scope="col">
											{ __( 'Reads', 'meiliscout' ) }
										</th>
										<th scope="col">
											{ __( 'Shown', 'meiliscout' ) }
										</th>
										<th scope="col">
											{ __(
												'In the index',
												'meiliscout'
											) }
										</th>
										<th scope="col">
											{ __( 'Drawn with', 'meiliscout' ) }
										</th>
									</tr>
								</thead>
								<tbody>
									{ suggest.sources.map( ( source ) => (
										<tr key={ source.key }>
											<td>
												{ source.label || source.key }{ ' ' }
												<code>{ source.key }</code>
											</td>
											<td>
												{ source.type === 'posts'
													? __(
															'Posts',
															'meiliscout'
													  )
													: __(
															'Terms',
															'meiliscout'
													  ) }
												{ ': ' }
												{ source.objects.join( ', ' ) }
											</td>
											<td>{ source.limit }</td>
											<td>{ source.documents ?? '—' }</td>
											<td>
												{ TEMPLATES[ source.template ] }
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
					</Line>
					{ suggest.source === 'php' && (
						<Line label={ __( 'Print it', 'meiliscout' ) }>
							<code>
								{ `meiliscout_suggest( '${ suggest.id }' );` }
							</code>
						</Line>
					) }
				</div>
			) }
		</section>
	);
};

// Where browsers reach Meilisearch, and the key their tokens are signed with
const Connection = ( { data, setData, showToast } ) => {
	const [ host, setHost ] = useState( data.public_host );
	const [ saving, setSaving ] = useState( false );
	const [ rotating, setRotating ] = useState( false );

	const saveHost = ( event ) => {
		event.preventDefault();
		setSaving( true );
		post( '/suggest/host', { public_host: host } )
			.then( ( result ) => {
				setData( result );
				showToast( __( 'Public URL saved.', 'meiliscout' ) );
			} )
			.catch( ( error ) => showToast( errorMessage( error ), 'error' ) )
			.finally( () => setSaving( false ) );
	};

	const rotate = () => {
		setRotating( true );
		post( '/suggest/key' )
			.then( ( result ) => {
				setData( result );
				showToast( __( 'Key replaced.', 'meiliscout' ) );
			} )
			.catch( ( error ) => showToast( errorMessage( error ), 'error' ) )
			.finally( () => setRotating( false ) );
	};

	return (
		<section className="ms-card" aria-labelledby="ms-suggest-connection">
			<div className="ms-card__head">
				<h2 id="ms-suggest-connection">
					{ __( 'How browsers reach Meilisearch', 'meiliscout' ) }
				</h2>
				<p>
					{ __(
						'Suggestions come straight from Meilisearch with a token: a key of its own (search only, posts and terms) signs it. The public URL is shared with the listings.',
						'meiliscout'
					) }
				</p>
			</div>
			<div className="ms-listing__lines">
				<Line label={ __( 'Public URL', 'meiliscout' ) }>
					<form className="ms-field__row" onSubmit={ saveHost }>
						<label
							htmlFor="ms-suggest-host"
							className="ms-visually-hidden"
						>
							{ __( 'Public URL of Meilisearch', 'meiliscout' ) }
						</label>
						<input
							id="ms-suggest-host"
							className="ms-input"
							type="url"
							value={ host }
							placeholder={ data.effective_host }
							onChange={ ( event ) =>
								setHost( event.target.value )
							}
						/>
						<button
							type="submit"
							className="ms-button"
							disabled={ saving || host === data.public_host }
						>
							{ __( 'Save', 'meiliscout' ) }
						</button>
					</form>
					<p className="ms-field__help">
						{ data.public_host
							? __(
									'The address browsers use to reach Meilisearch.',
									'meiliscout'
							  )
							: sprintf(
									/* translators: %s: the address the server uses */
									__(
										'Left empty, browsers use the address the server uses (%s).',
										'meiliscout'
									),
									data.effective_host
							  ) }
					</p>
				</Line>
				<Line label={ __( 'Key', 'meiliscout' ) }>
					{ data.key ? (
						<>
							<code>{ data.key.uid }</code>{ ' ' }
							<span className="ms-table__muted">
								{ __( 'search only', 'meiliscout' ) }
							</span>
						</>
					) : (
						<span className="ms-table__muted">
							{ __(
								'Made the first time a field is printed.',
								'meiliscout'
							) }
						</span>
					) }
					{ data.key && (
						<p>
							<button
								type="button"
								className="ms-button"
								onClick={ rotate }
								disabled={ rotating }
							>
								{ rotating
									? __( 'Replacing…', 'meiliscout' )
									: __( 'Replace the key', 'meiliscout' ) }
							</button>
						</p>
					) }
				</Line>
			</div>
		</section>
	);
};

const Suggest = () => {
	const resource = useResource( '/suggest' );
	const [ toast, showToast ] = useToast();
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

	const errors = data.suggests.filter(
		( suggest ) => worst( suggest ) === 'error'
	).length;

	return (
		<div className="ms-wrap ms-page" style={ { gap: 20 } }>
			{ toast }
			<section className="ms-card" aria-labelledby="ms-suggest-title">
				<div className="ms-card__head ms-card__head--row">
					<div>
						<h2 id="ms-suggest-title">
							{ __( 'Suggestions', 'meiliscout' ) }
						</h2>
						<p>
							{ __(
								'The search fields with suggestions the site declares, in PHP or with the Search with suggestions block, and what may keep them from working.',
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
				{ errors > 0 && (
					<div className="ms-card__body">
						<Banner tone="error" icon="alert">
							<p>
								{ sprintf(
									/* translators: %d: number of fields */
									_n(
										'%d field has errors: it does not suggest, and its form searches as a plain search.',
										'%d fields have errors: they do not suggest, and their forms search as plain searches.',
										errors,
										'meiliscout'
									),
									errors
								) }
							</p>
						</Banner>
					</div>
				) }
				{ ! data.suggests.length && (
					<p className="ms-empty">
						{ __(
							'No field is declared: add a Search with suggestions block to a page, or call meiliscout_register_suggest().',
							'meiliscout'
						) }
					</p>
				) }
			</section>

			{ data.suggests.map( ( suggest ) => (
				<SuggestCard key={ suggest.id } suggest={ suggest } />
			) ) }

			<Connection
				key={ data.public_host }
				data={ data }
				setData={ resource.setData }
				showToast={ showToast }
			/>
		</div>
	);
};

export default Suggest;
