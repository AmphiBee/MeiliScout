import { useEffect, useRef } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { useResource } from '../components';
import { number, percent } from '../format';

const POLL_INTERVAL = 3000;

const embeddingNote = ( { documents, requests } ) =>
	documents === null
		? sprintf(
				/* translators: %s: a number of calls to the embedder */
				__(
					'Computing the vectors with the embedder: %s calls so far.',
					'meiliscout'
				),
				number( requests )
		  )
		: sprintf(
				/* translators: 1: a number of documents, 2: a number of calls to the embedder */
				__(
					'Computing the vectors of %1$s documents with the embedder: %2$s calls so far.',
					'meiliscout'
				),
				number( documents ),
				number( requests )
		  );

const batchNote = ( { percentage, embedding } ) => {
	if ( embedding ) {
		return embeddingNote( embedding );
	}

	return percentage === null
		? __( 'Waiting for Meilisearch to start on them.', 'meiliscout' )
		: sprintf(
				/* translators: %s: a percentage */
				__( 'Indexing: %s of the current batch.', 'meiliscout' ),
				percent( percentage )
		  );
};

const Pending = ( { backlog } ) => {
	const { tasks, percentage } = backlog;

	return (
		<section className="ms-card" aria-labelledby="ms-backlog-title">
			<div className="ms-card__body">
				<div className="ms-section-head">
					<h2 id="ms-backlog-title" style={ { fontSize: 18 } }>
						{ __(
							'Meilisearch is still processing the indexation',
							'meiliscout'
						) }
					</h2>
					<span className="ms-hint" aria-live="polite">
						{ sprintf(
							/* translators: %s: a number of tasks */
							_n(
								'%s task left',
								'%s tasks left',
								tasks,
								'meiliscout'
							),
							number( tasks )
						) }
					</span>
				</div>
				<div
					className={
						'ms-progress' +
						( percentage === null
							? ' ms-progress--indeterminate'
							: '' )
					}
					role="progressbar"
					aria-valuemin={ 0 }
					aria-valuemax={ 100 }
					aria-valuenow={ percentage ?? undefined }
					aria-label={ __(
						'Progress of the current batch',
						'meiliscout'
					) }
				>
					<div
						className="ms-progress__bar"
						style={
							percentage === null
								? undefined
								: { width: percentage + '%' }
						}
					/>
				</div>
				<p className="ms-hint">{ batchNote( backlog ) }</p>
				{ backlog.embedding?.failed > 0 && (
					<p className="ms-inline-note">
						{ sprintf(
							/* translators: %s: a number of calls to the embedder */
							_n(
								'%s call to the embedder failed. Meilisearch tries again, then fails the task if the embedder stays out of reach: check it is running.',
								'%s calls to the embedder failed. Meilisearch tries again, then fails the task if the embedder stays out of reach: check it is running.',
								backlog.embedding.failed,
								'meiliscout'
							),
							number( backlog.embedding.failed )
						) }
					</p>
				) }
				<p className="ms-hint">
					{ __(
						'MeiliScout has sent every document. Until Meilisearch is done, searches show the indexes as they were.',
						'meiliscout'
					) }
				</p>
			</div>
		</section>
	);
};

/**
 * What Meilisearch still has to do once a run sent its documents, then that the run completed.
 *
 * @param {Object}     props
 * @param {boolean}    props.completed Whether the last run completed.
 * @param {() => void} props.onDone    Called when Meilisearch is done.
 */
const MeilisearchBacklog = ( { completed, onDone } ) => {
	const backlog = useResource( '/indexation/backlog' );
	const tasks = backlog.data?.tasks ?? 0;

	useEffect( () => {
		if ( tasks === 0 ) {
			return;
		}
		const timer = setTimeout( backlog.reload, POLL_INTERVAL );

		return () => clearTimeout( timer );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ backlog.data ] );

	const previous = useRef( tasks );
	useEffect( () => {
		if ( previous.current > 0 && tasks === 0 ) {
			onDone();
		}
		previous.current = tasks;
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ tasks ] );

	if ( tasks > 0 ) {
		return <Pending backlog={ backlog.data } />;
	}

	// Meilisearch's queue unknown: the run's own state is all there is
	const known = backlog.data !== null || backlog.error !== null;

	return completed && known ? (
		<p className="ms-status ms-status--success" role="status">
			{ __( 'The last indexation completed.', 'meiliscout' ) }
		</p>
	) : null;
};

export default MeilisearchBacklog;
