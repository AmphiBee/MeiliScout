import { createInterpolateElement, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { post, errorMessage } from '../api';
import {
	ActivityTable,
	Banner,
	Icon,
	Pill,
	Skeleton,
	useToast,
} from '../components';
import { agoIso, number, size } from '../format';
import { href } from '../routes';

const INDEX_LABELS = {
	posts: __( 'Content', 'meiliscout' ),
	taxonomies: __( 'Terms', 'meiliscout' ),
};

const REALTIME_LABELS = {
	shutdown: __( 'Active · at the end of the request', 'meiliscout' ),
	async: __( 'Active · async queue', 'meiliscout' ),
	off: __( 'Off', 'meiliscout' ),
};

/**
 * A reason a query ran on MySQL, as the plugin records it, in a few words.
 *
 * @param {string} reason e.g. unsupported_arg:author, unindexed_meta:price.
 * @return {string} The words.
 */
const fallbackReason = ( reason ) => {
	const [ kind, detail ] = reason.split( ':' );

	switch ( kind ) {
		case 'unsupported_arg':
		case 'unsupported_orderby':
		case 'unsupported_compare':
			return detail;
		case 'unindexed_meta':
			return detail
				? /* translators: %s: a meta key */
				  sprintf( __( 'field %s', 'meiliscout' ), detail )
				: __( 'fields not indexed', 'meiliscout' );
		case 'unindexed_status':
			/* translators: %s: a post status */
			return sprintf( __( 'status %s', 'meiliscout' ), detail );
		case 'unindexed_type':
			/* translators: %s: a post type */
			return sprintf( __( 'type %s', 'meiliscout' ), detail );
		case 'engine_error':
			return __( 'Meilisearch error', 'meiliscout' );
		case 'unreachable':
			return __( 'Meilisearch unreachable', 'meiliscout' );
		default:
			return reason;
	}
};

const FallbackReasons = ( { reasons } ) => {
	const entries = Object.entries( reasons ?? {} ).slice( 0, 3 );

	if ( ! entries.length ) {
		return null;
	}

	return (
		<span className="ms-health__detail">
			{ entries
				.map(
					( [ reason, count ] ) =>
						`${ number( count ) } ${ fallbackReason( reason ) }`
				)
				.join( ' · ' ) }
		</span>
	);
};

const IndexState = ( { index, running } ) => {
	if ( index.error ) {
		return <Pill tone="error">{ __( 'Error', 'meiliscout' ) }</Pill>;
	}
	if ( ! index.exists ) {
		return (
			<Pill tone="warning">{ __( 'Not created', 'meiliscout' ) }</Pill>
		);
	}
	if ( index.is_indexing || running ) {
		return <Pill tone="info">{ __( 'Indexing', 'meiliscout' ) }</Pill>;
	}

	return <Pill tone="success">{ __( 'Up to date', 'meiliscout' ) }</Pill>;
};

const IndexCard = ( { index, running } ) => (
	<article className="ms-card">
		<div className="ms-index__head">
			<div className="ms-index__title">
				<strong>{ INDEX_LABELS[ index.base ] ?? index.base }</strong>
				<span>{ index.name }</span>
			</div>
			<IndexState index={ index } running={ running } />
		</div>
		{ index.exists ? (
			<div className="ms-stats">
				<div className="ms-stat">
					<span className="ms-stat__label">
						{ __( 'Documents', 'meiliscout' ) }
					</span>
					<span className="ms-stat__value">
						{ number( index.documents ) }
					</span>
				</div>
				<div className="ms-stat">
					<span className="ms-stat__label">
						{ __( 'Size', 'meiliscout' ) }
					</span>
					<span className="ms-stat__value">
						{ size( index.size ) }
					</span>
				</div>
				<div className="ms-stat">
					<span className="ms-stat__label">
						{ __( 'Updated', 'meiliscout' ) }
					</span>
					<span className="ms-stat__value ms-stat__value--small">
						{ agoIso( index.updated_at ) }
					</span>
				</div>
			</div>
		) : (
			<p className="ms-empty">
				{ index.error ??
					__(
						'Run a full indexation to create this index.',
						'meiliscout'
					) }
			</p>
		) }
		<div className="ms-card__foot">
			{ index.base === 'posts' ? (
				<>
					<a href={ href( 'search' ) }>
						{ __( 'Search preview', 'meiliscout' ) }
					</a>
					<a href={ href( 'content', 'types' ) }>
						{ __( 'Indexed content', 'meiliscout' ) }
					</a>
				</>
			) : (
				<a href={ href( 'content', 'taxonomies' ) }>
					{ __( 'Indexed taxonomies', 'meiliscout' ) }
				</a>
			) }
		</div>
	</article>
);

const selectionSummary = ( selection ) =>
	[
		sprintf(
			/* translators: %d: number of post types */
			_n( '%d type', '%d types', selection.post_types, 'meiliscout' ),
			selection.post_types
		),
		sprintf(
			/* translators: %d: number of taxonomies */
			_n(
				'%d taxonomy',
				'%d taxonomies',
				selection.taxonomies,
				'meiliscout'
			),
			selection.taxonomies
		),
		sprintf(
			/* translators: %d: number of custom fields */
			_n( '%d field', '%d fields', selection.meta_keys, 'meiliscout' ),
			selection.meta_keys
		),
	].join( ' · ' );

const LegacyIndexes = ( { legacy, onDeleted } ) => {
	const [ deleting, setDeleting ] = useState( false );
	const [ toast, showToast ] = useToast();

	const remove = () => {
		setDeleting( true );
		post( '/legacy-indexes/delete' )
			.then( () => {
				showToast(
					__( 'The previous indexes were deleted.', 'meiliscout' )
				);
				onDeleted();
			} )
			.catch( ( error ) => showToast( errorMessage( error ), 'error' ) )
			.finally( () => setDeleting( false ) );
	};

	return (
		<Banner
			tone="info"
			icon="info"
			title={ __( 'Previous indexes can be deleted', 'meiliscout' ) }
			actions={
				<button
					type="button"
					className="ms-button"
					onClick={ remove }
					disabled={ deleting }
				>
					{ deleting
						? __( 'Deleting…', 'meiliscout' )
						: __( 'Delete them', 'meiliscout' ) }
				</button>
			}
		>
			{ toast }
			<p>
				{ createInterpolateElement(
					__(
						'Searches no longer read these indexes: <indexes />',
						'meiliscout'
					),
					{ indexes: <code>{ legacy.join( ', ' ) }</code> }
				) }
			</p>
		</Banner>
	);
};

const Overview = ( { overview } ) => {
	const { data, error, reload } = overview;

	if ( error && ! data ) {
		return (
			<div className="ms-wrap ms-page">
				<Banner tone="error" icon="alert" title={ error } />
			</div>
		);
	}

	if ( ! data ) {
		return (
			<div className="ms-wrap ms-page" aria-busy="true">
				<div className="ms-grid">
					<Skeleton height={ 180 } />
					<Skeleton height={ 180 } />
				</div>
				<Skeleton height={ 240 } />
			</div>
		);
	}

	const { connection, migration, indexes, realtime, selection, fallbacks } =
		data;
	const running = [ 'waiting', 'running' ].includes( data.run?.status );

	return (
		<div className="ms-wrap ms-page">
			{ connection.configured && ! connection.reachable && (
				<Banner
					tone="error"
					icon="alert"
					title={ __(
						'Meilisearch cannot be reached',
						'meiliscout'
					) }
					actions={
						<a
							className="ms-button"
							href={ href( 'settings', 'connection' ) }
						>
							{ __( 'Check the connection', 'meiliscout' ) }
						</a>
					}
				>
					<p>
						{ __(
							'Searches run on MySQL meanwhile, and saved content is not indexed.',
							'meiliscout'
						) }
					</p>
				</Banner>
			) }

			{ migration.pending && ! running && (
				<Banner
					title={ __( 'A full indexation is needed', 'meiliscout' ) }
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
						{ createInterpolateElement(
							__(
								'Searches still use <indexes /> in the previous format. Content saved meanwhile stays up to date in both.',
								'meiliscout'
							),
							{
								indexes: (
									<code>
										{ migration.active.join( ', ' ) }
									</code>
								),
							}
						) }
					</p>
				</Banner>
			) }

			{ data.integration.indexed &&
				! data.integration.search &&
				! migration.pending &&
				! data.needs_indexation &&
				! running && (
					<Banner
						tone="info"
						icon="search"
						title={ __(
							'Serve the site search with Meilisearch',
							'meiliscout'
						) }
						actions={
							<a
								className="ms-button ms-button--primary"
								href={ href( 'settings', 'queries' ) }
							>
								{ __( 'Choose the queries', 'meiliscout' ) }
							</a>
						}
					>
						<p>
							{ __(
								'The content is indexed. The site search, archives, REST searches and admin lists can be served by Meilisearch, each on its own; any query can also ask with use_meilisearch.',
								'meiliscout'
							) }
						</p>
					</Banner>
				) }

			{ ! migration.pending && data.needs_indexation && ! running && (
				<Banner
					title={ __( 'The indexed content changed', 'meiliscout' ) }
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
							'A full indexation applies the new selection to the indexes.',
							'meiliscout'
						) }
					</p>
				</Banner>
			) }

			{ ! migration.pending && migration.legacy.length > 0 && (
				<LegacyIndexes
					legacy={ migration.legacy }
					onDeleted={ reload }
				/>
			) }

			{ connection.reachable && (
				<section
					aria-labelledby="ms-indexes-title"
					className="ms-stack"
				>
					<div className="ms-section-head">
						<h2 id="ms-indexes-title">
							{ __( 'Indexes', 'meiliscout' ) }
						</h2>
						<a className="ms-button" href={ href( 'indexation' ) }>
							<Icon name="refresh" />
							{ __( 'Re-index', 'meiliscout' ) }
						</a>
					</div>
					<div className="ms-grid">
						{ indexes.map( ( index ) => (
							<IndexCard
								key={ index.base }
								index={ index }
								running={ running }
							/>
						) ) }
					</div>
				</section>
			) }

			<section
				className="ms-grid ms-grid--small"
				aria-label={ __( 'State', 'meiliscout' ) }
			>
				<div className="ms-card ms-health">
					<span className="ms-health__label">
						{ __( 'Real-time indexing', 'meiliscout' ) }
					</span>
					<span className="ms-health__value">
						{ REALTIME_LABELS[ realtime.mode ] }
					</span>
					<a href={ href( 'settings', 'realtime' ) }>
						{ realtime.mode === 'shutdown'
							? __( 'Switch to the async queue', 'meiliscout' )
							: __( 'Change the mode', 'meiliscout' ) }
					</a>
				</div>
				<div className="ms-card ms-health">
					<span className="ms-health__label">
						{ __( 'Indexed content', 'meiliscout' ) }
					</span>
					<span className="ms-health__value">
						{ selectionSummary( selection ) }
					</span>
					<a href={ href( 'content' ) }>
						{ __( 'Edit the selection', 'meiliscout' ) }
					</a>
				</div>
				<div className="ms-card ms-health">
					<span className="ms-health__label">
						{ __( 'Queries served by Meilisearch', 'meiliscout' ) }
					</span>
					<span className="ms-health__value">
						{ sprintf(
							/* translators: %s: number of queries */
							__( 'MySQL fallback: %s in 24 h', 'meiliscout' ),
							number( fallbacks.total )
						) }
					</span>
					<FallbackReasons reasons={ fallbacks.reasons } />
					{ fallbacks.meta > 0 ? (
						<a href={ href( 'content', 'fields' ) }>
							{ sprintf(
								/* translators: %s: number of queries */
								_n(
									'%s on a field not indexed',
									'%s on fields not indexed',
									fallbacks.meta,
									'meiliscout'
								),
								number( fallbacks.meta )
							) }
						</a>
					) : (
						<a href={ href( 'indexation', 'log' ) }>
							{ __( 'See the log', 'meiliscout' ) }
						</a>
					) }
				</div>
			</section>

			<section className="ms-card" aria-labelledby="ms-activity-title">
				<div className="ms-card__head ms-card__head--row">
					<h2 id="ms-activity-title">
						{ __( 'Recent activity', 'meiliscout' ) }
					</h2>
					<a href={ href( 'indexation', 'log' ) }>
						{ __( 'Whole log', 'meiliscout' ) }
					</a>
				</div>
				<ActivityTable entries={ data.activity } onRetried={ reload } />
			</section>
		</div>
	);
};

export default Overview;
