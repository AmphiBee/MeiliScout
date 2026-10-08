import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { post, errorMessage } from '../api';
import { Banner, Pill } from '../components';
import { number } from '../format';

const KINDS = {
	posts: {
		label: __( 'WP_Query', 'meiliscout' ),
		docs: 'https://github.com/AmphiBee/MeiliScout/blob/main/docs/WP_QUERY.md',
		example: `{
  "post_type": "post",
  "category_name": "news",
  "posts_per_page": 10
}`,
	},
	terms: {
		label: __( 'get_terms()', 'meiliscout' ),
		docs: 'https://github.com/AmphiBee/MeiliScout/blob/main/docs/TERM_QUERY.md',
		example: `{
  "taxonomy": "category",
  "hide_empty": false,
  "child_of": 0,
  "orderby": "name"
}`,
	},
};

const MODES = [
	{
		id: 'order',
		label: __( 'Same result, same order', 'meiliscout' ),
	},
	{ id: 'set', label: __( 'Same posts, any order', 'meiliscout' ) },
	{ id: 'count', label: __( 'Same number of posts', 'meiliscout' ) },
	{
		id: 'search',
		label: __( 'A search: compare the overlap only', 'meiliscout' ),
	},
];

const OUTCOMES = {
	OK: {
		tone: 'success',
		label: __( 'Same result', 'meiliscout' ),
	},
	DIFF: {
		tone: 'error',
		label: __( 'Different result', 'meiliscout' ),
	},
	FALLBACK: {
		tone: 'warning',
		label: __( 'Served by MySQL', 'meiliscout' ),
	},
	INFO: {
		tone: 'info',
		label: __( 'Search: ranked differently', 'meiliscout' ),
	},
	ERROR: {
		tone: 'error',
		label: __( 'Error', 'meiliscout' ),
	},
};

const PostList = ( { title, side, kind } ) => (
	<section className="ms-card ms-compare__side">
		<div className="ms-card__head">
			<h2>{ title }</h2>
			<p>
				{ side.found === null && '—' }
				{ side.found !== null &&
					kind === 'posts' &&
					sprintf(
						/* translators: %s: number of posts */
						__( 'found_posts: %s', 'meiliscout' ),
						number( side.found )
					) }
				{ side.found !== null &&
					kind === 'terms' &&
					sprintf(
						/* translators: %s: number of values get_terms() returned */
						__( 'Returned: %s', 'meiliscout' ),
						number( side.found )
					) }
			</p>
		</div>
		{ side.posts.length ? (
			<ol className="ms-compare__list">
				{ side.posts.map( ( item ) => (
					<li key={ item.id }>
						<span className="ms-mono">
							{ kind === 'terms'
								? '[' + item.id + ']'
								: '#' + item.id }
						</span>{ ' ' }
						{ item.title }{ ' ' }
						<span className="ms-hint">
							{ item.type } · { item.status }
						</span>
					</li>
				) ) }
			</ol>
		) : (
			<p className="ms-empty">
				{ kind === 'terms'
					? __( 'Nothing.', 'meiliscout' )
					: __( 'No posts.', 'meiliscout' ) }
			</p>
		) }
	</section>
);

/**
 * Runs WP_Query arguments on MySQL and on Meilisearch, side by side.
 *
 * @return {Element} The tester.
 */
const WpQueryTester = () => {
	const [ kind, setKind ] = useState( 'posts' );
	const [ text, setText ] = useState( KINDS.posts.example );
	const [ mode, setMode ] = useState( 'order' );
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ running, setRunning ] = useState( false );

	const run = ( event ) => {
		event.preventDefault();

		let args;

		try {
			args = JSON.parse( text );
		} catch ( reason ) {
			setError(
				sprintf(
					/* translators: %s: the JSON parser's message */
					__( 'The arguments are not valid JSON: %s', 'meiliscout' ),
					reason.message
				)
			);

			return;
		}

		setRunning( true );
		setError( null );

		post( '/wp-query', { args, mode, kind } )
			.then( setResult )
			.catch( ( reason ) => setError( errorMessage( reason ) ) )
			.finally( () => setRunning( false ) );
	};

	const outcome = result && OUTCOMES[ result.outcome ];

	return (
		<>
			<form className="ms-card ms-tester" onSubmit={ run }>
				<div className="ms-card__head">
					<h2>
						{ kind === 'terms'
							? __( 'get_terms() arguments', 'meiliscout' )
							: __( 'WP_Query arguments', 'meiliscout' ) }
					</h2>
					<p>
						{ __(
							'Runs the query on MySQL, then with use_meilisearch, as you, and compares the two.',
							'meiliscout'
						) }{ ' ' }
						<a
							href={ KINDS[ kind ].docs }
							target="_blank"
							rel="noreferrer"
						>
							{ __(
								'What is translated, and why a query runs on MySQL',
								'meiliscout'
							) }
						</a>
					</p>
				</div>
				<div className="ms-card__body">
					<div
						className="ms-segmented"
						role="group"
						aria-label={ __( 'Query', 'meiliscout' ) }
					>
						{ Object.entries( KINDS ).map( ( [ id, item ] ) => (
							<button
								key={ id }
								type="button"
								className="ms-segmented__item"
								aria-pressed={ kind === id }
								onClick={ () => {
									setKind( id );
									setText( item.example );
									setResult( null );
								} }
							>
								{ item.label }
							</button>
						) ) }
					</div>
					<label htmlFor="ms-wp-query" className="ms-visually-hidden">
						{ __( 'Arguments, as JSON', 'meiliscout' ) }
					</label>
					<textarea
						id="ms-wp-query"
						className="ms-input ms-mono"
						rows={ 8 }
						spellCheck={ false }
						value={ text }
						onChange={ ( event ) => setText( event.target.value ) }
					/>
					<div className="ms-tester__actions">
						<label htmlFor="ms-wp-query-mode">
							{ __( 'Compare', 'meiliscout' ) }
						</label>
						<select
							id="ms-wp-query-mode"
							value={ mode }
							onChange={ ( event ) =>
								setMode( event.target.value )
							}
						>
							{ MODES.map( ( item ) => (
								<option key={ item.id } value={ item.id }>
									{ item.label }
								</option>
							) ) }
						</select>
						<button
							type="submit"
							className="ms-button ms-button--primary"
							disabled={ running }
						>
							{ running
								? __( 'Running…', 'meiliscout' )
								: __( 'Run on both', 'meiliscout' ) }
						</button>
					</div>
				</div>
			</form>

			{ error && <Banner tone="error" icon="alert" title={ error } /> }

			{ result && (
				<>
					<div className="ms-tester__outcome" aria-live="polite">
						<Pill tone={ outcome?.tone }>
							{ outcome?.label ?? result.outcome }
						</Pill>
						{ result.reason && <code>{ result.reason }</code> }
						{ result.notes
							.filter( ( note ) => note !== result.reason )
							.map( ( note ) => (
								<span className="ms-hint" key={ note }>
									{ note }
								</span>
							) ) }
					</div>
					<div className="ms-compare">
						<PostList
							title="MySQL"
							side={ result.mysql }
							kind={ kind }
						/>
						<PostList
							title="Meilisearch"
							side={ result.meilisearch }
							kind={ kind }
						/>
					</div>
					{ result.params && (
						<details className="ms-details">
							<summary>
								{ __(
									'Request sent to Meilisearch',
									'meiliscout'
								) }
							</summary>
							<pre>
								{ JSON.stringify( result.params, null, 2 ) }
							</pre>
						</details>
					) }
				</>
			) }
		</>
	);
};

export default WpQueryTester;
