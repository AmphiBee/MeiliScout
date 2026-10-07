import {
	createInterpolateElement,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { get, post, errorMessage } from '../api';
import {
	ActivityTable,
	Banner,
	Icon,
	Skeleton,
	useResource,
	useToast,
} from '../components';
import { number } from '../format';

const POLL_INTERVAL = 2000;

/**
 * Past this, a run still waiting means WP-Cron does not run.
 */
const CRON_WARNING_AFTER = 45000;

const MODES = [
	{
		id: 'update',
		title: __( 'Update', 'meiliscout' ),
		text: __(
			'Adds and updates the documents, removes the ones no longer published. The fastest.',
			'meiliscout'
		),
	},
	{
		id: 'rebuild',
		title: __( 'Rebuild', 'meiliscout' ),
		text: __(
			'Builds each index aside, then swaps it with the current one. Starts from scratch, without downtime.',
			'meiliscout'
		),
	},
];

const FILTERS = [
	{ id: 'all', label: __( 'All', 'meiliscout' ), query: {} },
	{
		id: 'errors',
		label: __( 'Failures', 'meiliscout' ),
		query: { status: 'error' },
	},
	{
		id: 'realtime',
		label: __( 'Real time', 'meiliscout' ),
		query: { kind: 'realtime' },
	},
	{ id: 'full', label: __( 'Full', 'meiliscout' ), query: { kind: 'full' } },
];

const KIND_LABELS = {
	posts: __( 'Content', 'meiliscout' ),
	terms: __( 'Terms', 'meiliscout' ),
};

const stepLabel = ( index ) => {
	const label = KIND_LABELS[ index.kind ] ?? index.name;

	if ( index.state === 'swapping' ) {
		return sprintf(
			/* translators: %s: Content or Terms */
			__( '%s · swapping with the live index', 'meiliscout' ),
			label
		);
	}
	if ( index.total === null || index.total === undefined ) {
		return label;
	}

	return index.state === 'pending'
		? label + ' · ' + number( index.total )
		: sprintf(
				/* translators: 1: Content or Terms, 2: items done, 3: items in all */
				__( '%1$s · %2$s of %3$s', 'meiliscout' ),
				label,
				number( Math.min( index.done, index.total ) ),
				number( index.total )
		  );
};

const STEP_CLASSES = { done: 'is-done', pending: '' };

const runTitle = ( run ) => {
	if ( run.status === 'waiting' ) {
		return __( 'Starting the indexation…', 'meiliscout' );
	}

	return run.mode === 'rebuild'
		? __( 'Rebuild in progress', 'meiliscout' )
		: __( 'Update in progress', 'meiliscout' );
};

const Progress = ( { run, waitingSince } ) => {
	const indexes = run.indexes ?? [];
	const total = indexes.reduce(
		( sum, index ) => sum + ( index.total ?? 0 ),
		0
	);
	const done = indexes.reduce(
		( sum, index ) =>
			sum +
			( index.state === 'done'
				? index.total ?? 0
				: Math.min( index.done, index.total ?? 0 ) ),
		0
	);
	const waiting = run.status === 'waiting';
	const percent = total > 0 ? Math.round( ( done / total ) * 100 ) : 0;
	const cronLate =
		waiting &&
		waitingSince &&
		Date.now() - waitingSince > CRON_WARNING_AFTER;

	return (
		<section className="ms-card" aria-labelledby="ms-run-title">
			<div className="ms-card__body">
				<div className="ms-section-head">
					<h2 id="ms-run-title" style={ { fontSize: 18 } }>
						{ runTitle( run ) }
					</h2>
					{ total > 0 && (
						<span className="ms-hint" aria-live="polite">
							{ sprintf(
								/* translators: 1: items done, 2: items in all */
								__( '%1$s of %2$s', 'meiliscout' ),
								number( done ),
								number( total )
							) }
						</span>
					) }
				</div>
				<div
					className={
						'ms-progress' +
						( waiting ? ' ms-progress--indeterminate' : '' )
					}
					role="progressbar"
					aria-valuemin={ 0 }
					aria-valuemax={ 100 }
					aria-valuenow={ waiting ? undefined : percent }
					aria-label={ __( 'Indexation progress', 'meiliscout' ) }
				>
					<div
						className="ms-progress__bar"
						style={ waiting ? undefined : { width: percent + '%' } }
					/>
				</div>
				{ indexes.length > 0 && (
					<ol className="ms-steps">
						{ indexes.map( ( index ) => (
							<li
								key={ index.name }
								className={
									STEP_CLASSES[ index.state ] ?? 'is-running'
								}
							>
								<span
									className="ms-step-dot"
									aria-hidden="true"
								>
									{ index.state === 'done' && (
										<Icon
											name="check"
											size={ 14 }
											strokeWidth={ 3 }
										/>
									) }
								</span>
								{ stepLabel( index ) }
							</li>
						) ) }
					</ol>
				) }
				{ cronLate ? (
					<p className="ms-inline-note">
						{ createInterpolateElement(
							__(
								'Still waiting for WP-Cron. If it is turned off on this site (<constant />), run <command /> from the server.',
								'meiliscout'
							),
							{
								constant: <code>DISABLE_WP_CRON</code>,
								command: <code>wp meiliscout index</code>,
							}
						) }
					</p>
				) : (
					<p className="ms-hint">
						{ __(
							'You can leave this page: the indexation goes on in the background.',
							'meiliscout'
						) }
					</p>
				) }
			</div>
		</section>
	);
};

const Start = ( { totals, run, onStarted } ) => {
	const [ mode, setMode ] = useState( 'update' );
	const [ starting, setStarting ] = useState( false );
	const [ toast, showToast ] = useToast();
	const items = totals.posts + totals.terms;

	const start = () => {
		setStarting( true );
		post( '/indexation', { mode } )
			.then( onStarted )
			.catch( ( error ) => showToast( errorMessage( error ), 'error' ) )
			.finally( () => setStarting( false ) );
	};

	return (
		<section className="ms-card" aria-labelledby="ms-start-title">
			{ toast }
			<div className="ms-card__body">
				<div
					style={ {
						display: 'flex',
						flexDirection: 'column',
						gap: 4,
					} }
				>
					<h2 id="ms-start-title" style={ { fontSize: 18 } }>
						{ __( 'Full indexation', 'meiliscout' ) }
					</h2>
					<p className="ms-hint" style={ { fontSize: 14 } }>
						{ sprintf(
							/* translators: 1: items in all, 2: posts, 3: terms */
							__(
								'%1$s items: %2$s contents and %3$s terms. Search stays available during the whole operation.',
								'meiliscout'
							),
							number( items ),
							number( totals.posts ),
							number( totals.terms )
						) }
					</p>
				</div>

				{ run.status === 'error' && (
					<p className="ms-status ms-status--error" role="alert">
						{ sprintf(
							/* translators: %s: the error message */
							__(
								'The last indexation failed: %s',
								'meiliscout'
							),
							run.error ?? ''
						) }
					</p>
				) }
				{ run.status === 'stalled' && (
					<p className="ms-status ms-status--error" role="alert">
						{ __(
							'The last indexation stopped without finishing, probably killed by a time or memory limit. Run it again, or from the server with wp meiliscout index.',
							'meiliscout'
						) }
					</p>
				) }

				<fieldset className="ms-choices ms-choices--row">
					<legend className="ms-visually-hidden">
						{ __( 'Kind of indexation', 'meiliscout' ) }
					</legend>
					{ MODES.map( ( candidate ) => (
						<label
							className="ms-choice"
							key={ candidate.id }
							htmlFor={ 'ms-mode-' + candidate.id }
						>
							<input
								id={ 'ms-mode-' + candidate.id }
								type="radio"
								name="ms-mode"
								value={ candidate.id }
								checked={ mode === candidate.id }
								onChange={ () => setMode( candidate.id ) }
							/>
							<span className="ms-choice__text">
								<span className="ms-choice__title">
									{ candidate.title }
								</span>
								<span className="ms-choice__desc">
									{ candidate.text }
								</span>
							</span>
						</label>
					) ) }
				</fieldset>

				<div
					style={ {
						display: 'flex',
						flexWrap: 'wrap',
						alignItems: 'center',
						gap: 16,
					} }
				>
					<button
						type="button"
						className="ms-button ms-button--primary ms-button--large"
						onClick={ start }
						disabled={ starting || items === 0 }
					>
						{ starting
							? __( 'Starting…', 'meiliscout' )
							: __( 'Run the indexation', 'meiliscout' ) }
					</button>
					<span className="ms-hint">
						{ createInterpolateElement(
							__(
								'For a large site, prefer <command />',
								'meiliscout'
							),
							{
								command: (
									<code>
										wp meiliscout index --chunk-size=50000
									</code>
								),
							}
						) }
					</span>
				</div>
			</div>
		</section>
	);
};

const Log = ( { refreshKey, onRetried } ) => {
	const [ filter, setFilter ] = useState( FILTERS[ 0 ] );
	const [ entries, setEntries ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		get( '/activity', { ...filter.query, limit: 100 } )
			.then( ( result ) => {
				setEntries( result );
				setError( null );
			} )
			.catch( ( reason ) => setError( errorMessage( reason ) ) );
	}, [ filter, refreshKey ] );

	return (
		<section id="log" className="ms-card" aria-labelledby="ms-log-title">
			<div className="ms-card__head ms-card__head--row">
				<h2 id="ms-log-title">{ __( 'Log', 'meiliscout' ) }</h2>
				<div
					className="ms-chips"
					role="group"
					aria-label={ __( 'Show', 'meiliscout' ) }
				>
					{ FILTERS.map( ( candidate ) => (
						<button
							key={ candidate.id }
							type="button"
							className="ms-chip"
							aria-pressed={ filter.id === candidate.id }
							onClick={ () => setFilter( candidate ) }
						>
							{ candidate.label }
						</button>
					) ) }
				</div>
			</div>
			{ error && <p className="ms-empty">{ error }</p> }
			{ ! error && entries === null && <Skeleton height={ 160 } /> }
			{ entries && (
				<ActivityTable
					entries={ entries }
					showKind
					onRetried={ onRetried }
				/>
			) }
		</section>
	);
};

const Indexation = ( { refreshOverview } ) => {
	const resource = useResource( '/indexation' );
	const [ logKey, setLogKey ] = useState( 0 );
	const waitingSince = useRef( null );
	const run = resource.data?.run;
	const active = [ 'waiting', 'running' ].includes( run?.status );

	// Follows the run while it goes on
	useEffect( () => {
		if ( ! active ) {
			waitingSince.current = null;

			return;
		}
		if ( run.status === 'waiting' && ! waitingSince.current ) {
			waitingSince.current = Date.now();
		}
		const timer = setTimeout( resource.reload, POLL_INTERVAL );

		return () => clearTimeout( timer );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ resource.data ] );

	// Once it ends, the log and the overview have news
	const previous = useRef( run?.status );
	useEffect( () => {
		if (
			[ 'waiting', 'running' ].includes( previous.current ) &&
			! active
		) {
			setLogKey( ( key ) => key + 1 );
			refreshOverview();
		}
		previous.current = run?.status;
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ run?.status ] );

	if ( resource.error && ! resource.data ) {
		return (
			<div className="ms-wrap ms-page">
				<Banner tone="error" icon="alert" title={ resource.error } />
			</div>
		);
	}

	if ( ! resource.data ) {
		return (
			<div className="ms-wrap ms-page" aria-busy="true">
				<Skeleton height={ 260 } />
				<Skeleton height={ 240 } />
			</div>
		);
	}

	return (
		<div className="ms-wrap ms-page">
			{ active ? (
				<Progress run={ run } waitingSince={ waitingSince.current } />
			) : (
				<Start
					totals={ resource.data.totals }
					run={ run }
					onStarted={ ( started ) =>
						resource.setData( { ...resource.data, run: started } )
					}
				/>
			) }
			{ run?.status === 'completed' && ! active && run.ended_at && (
				<p className="ms-status ms-status--success" role="status">
					{ __( 'The last indexation completed.', 'meiliscout' ) }
				</p>
			) }
			<Log
				refreshKey={ logKey }
				onRetried={ () => setLogKey( ( key ) => key + 1 ) }
			/>
		</div>
	);
};

export default Indexation;
