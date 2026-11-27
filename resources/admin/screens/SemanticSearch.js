import { createInterpolateElement } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { Pill, Skeleton, useResource } from '../components';
import { agoIso, number } from '../format';

const DOCS_URL =
	'https://github.com/AmphiBee/MeiliScout/blob/main/docs/EMBEDDERS.md';

const INDEX_LABELS = {
	posts: __( 'Content', 'meiliscout' ),
	taxonomies: __( 'Terms', 'meiliscout' ),
};

const DocsLink = ( { children } ) => (
	<a href={ DOCS_URL } target="_blank" rel="noreferrer">
		{ children }
	</a>
);

const Vectors = ( { index } ) => {
	if ( index.documents === null ) {
		return <span className="ms-table__muted">—</span>;
	}

	const missing = index.documents - index.embedded;

	return (
		<>
			{ sprintf(
				/* translators: 1: documents with their vectors, 2: documents in all */
				__( '%1$s of %2$s', 'meiliscout' ),
				number( index.embedded ),
				number( index.documents )
			) }
			{ missing > 0 && (
				<>
					{ ' ' }
					<Pill tone="warning">
						{ sprintf(
							/* translators: %s: a number of documents */
							_n(
								'%s without a vector',
								'%s without a vector',
								missing,
								'meiliscout'
							),
							number( missing )
						) }
					</Pill>
				</>
			) }
		</>
	);
};

const Failures = ( { index } ) => {
	if ( index.failed === null ) {
		return <span className="ms-table__muted">—</span>;
	}

	const last = index.last_failure && (
		<span className="ms-table__muted">
			{ sprintf(
				/* translators: %s: how long ago, e.g. 5 days ago */
				__( 'Last one %s', 'meiliscout' ),
				agoIso( index.last_failure )
			) }
		</span>
	);

	return (
		<div className="ms-stack ms-stack--tight">
			{ index.failed > 0 ? (
				<Pill tone="error">
					{ sprintf(
						/* translators: %s: a number of failed tasks */
						_n(
							'%s to deal with',
							'%s to deal with',
							index.failed,
							'meiliscout'
						),
						number( index.failed )
					) }
				</Pill>
			) : (
				<span>{ __( 'None to deal with', 'meiliscout' ) }</span>
			) }
			{ last }
		</div>
	);
};

const IndexRow = ( { index } ) => (
	<tr>
		<td>
			{ INDEX_LABELS[ index.base ] ?? index.base }{ ' ' }
			<code>{ index.index }</code>
		</td>
		<td>
			{ index.embedders.map( ( embedder ) => (
				<div key={ embedder.name }>
					<code>{ embedder.name }</code>{ ' ' }
					<span className="ms-table__muted">
						{ [ embedder.source, embedder.model ]
							.filter( Boolean )
							.join( ' · ' ) }
					</span>
				</div>
			) ) }
		</td>
		<td>
			<Vectors index={ index } />
		</td>
		<td>
			<Failures index={ index } />
		</td>
	</tr>
);

const Indexes = ( { indexes } ) => (
	<div className="ms-table-wrap">
		<table className="ms-table">
			<thead>
				<tr>
					<th scope="col">{ __( 'Index', 'meiliscout' ) }</th>
					<th scope="col">{ __( 'Embedder', 'meiliscout' ) }</th>
					<th scope="col">{ __( 'Vectors', 'meiliscout' ) }</th>
					<th scope="col">{ __( 'Failed tasks', 'meiliscout' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ indexes.map( ( index ) => (
					<IndexRow key={ index.base } index={ index } />
				) ) }
			</tbody>
		</table>
	</div>
);

const Body = ( { resource } ) => {
	if ( resource.error && ! resource.data ) {
		return <p className="ms-empty">{ resource.error }</p>;
	}
	if ( ! resource.data ) {
		return <Skeleton height={ 120 } />;
	}
	if ( resource.data.length === 0 ) {
		return (
			<p className="ms-empty">
				{ createInterpolateElement(
					__(
						'No embedder is declared: searches find content by its words only. <link>Declare one</link> to search by meaning too.',
						'meiliscout'
					),
					{ link: <DocsLink /> }
				) }
			</p>
		);
	}

	return <Indexes indexes={ resource.data } />;
};

/**
 * The embedders declared in code, by index: their vectors and their failed tasks.
 */
const SemanticSearch = () => {
	const resource = useResource( '/content/embedders' );

	return (
		<section
			id="semantic"
			className="ms-card"
			aria-labelledby="ms-semantic-title"
		>
			<div className="ms-card__head">
				<h2 id="ms-semantic-title">
					{ __( 'Semantic search', 'meiliscout' ) }
				</h2>
				<p>
					{ createInterpolateElement(
						__(
							'An embedder turns each document, and each search, into a vector: a hybrid search finds content by meaning as well as by words. Embedders are declared in code with <code>meiliscout_register_embedder()</code>, and their API key is never stored in WordPress. <link>Documentation</link>',
							'meiliscout'
						),
						{ code: <code />, link: <DocsLink /> }
					) }
				</p>
			</div>
			<Body resource={ resource } />
		</section>
	);
};

export default SemanticSearch;
