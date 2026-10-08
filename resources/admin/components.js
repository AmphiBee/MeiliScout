import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { get, post, errorMessage } from './api';
import { ago, duration, number } from './format';

/* Icons, drawn with the current color. */

const paths = {
	search: (
		<>
			<circle cx="10.5" cy="10.5" r="6" />
			<path d="M15 15l5 5" />
		</>
	),
	refresh: (
		<>
			<path d="M21 12a9 9 0 1 1-3-6.7" />
			<path d="M21 4v5h-5" />
		</>
	),
	lock: (
		<>
			<rect x="4" y="11" width="16" height="10" rx="2" />
			<path d="M8 11V7a4 4 0 0 1 8 0v4" />
		</>
	),
	alert: (
		<>
			<path d="M12 3l9.5 17h-19z" />
			<path d="M12 10v4M12 17.5v.5" />
		</>
	),
	info: (
		<>
			<circle cx="12" cy="12" r="9" />
			<path d="M12 11v6M12 7.5v.5" />
		</>
	),
	up: <path d="M6 15l6-6 6 6" />,
	down: <path d="M6 9l6 6 6-6" />,
	close: <path d="M6 6l12 12M18 6L6 18" />,
	check: <path d="M5 12.5l4.5 4.5L19 7.5" />,
};

export const Icon = ( { name, size = 16, strokeWidth = 2, ...props } ) => (
	<svg
		width={ size }
		height={ size }
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth={ strokeWidth }
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
		focusable="false"
		{ ...props }
	>
		{ paths[ name ] }
	</svg>
);

export const Pill = ( { tone, children } ) => (
	<span className={ 'ms-pill' + ( tone ? ' ms-pill--' + tone : '' ) }>
		{ children }
	</span>
);

export const Switch = ( { checked, onChange, label, disabled } ) => (
	<button
		type="button"
		role="switch"
		className="ms-switch"
		aria-checked={ checked ? 'true' : 'false' }
		aria-label={ label }
		disabled={ disabled }
		onClick={ () => onChange( ! checked ) }
	>
		<span className="ms-switch__knob" />
	</button>
);

export const Banner = ( {
	tone = 'attention',
	icon = 'refresh',
	title,
	children,
	actions,
} ) => (
	<section
		className={ 'ms-banner ms-banner--' + tone }
		role={ tone === 'error' ? 'alert' : undefined }
	>
		<span className="ms-banner__icon">
			<Icon name={ icon } size={ 24 } />
		</span>
		<div className="ms-banner__text">
			{ title && <h2>{ title }</h2> }
			{ children }
		</div>
		{ actions && <div className="ms-banner__actions">{ actions }</div> }
	</section>
);

export const Skeleton = ( { height = 120 } ) => (
	<div className="ms-skeleton" style={ { height } } aria-hidden="true" />
);

/**
 * Loads a REST resource, and reloads it on demand.
 *
 * @param {string} path
 * @param {Object} query
 * @return {Object} { data, error, loading, reload, setData }
 */
export const useResource = ( path, query = null ) => {
	const [ state, setState ] = useState( {
		data: null,
		error: null,
		loading: true,
	} );
	const queryKey = JSON.stringify( query );

	const reload = useCallback( () => {
		setState( ( previous ) => ( { ...previous, loading: true } ) );

		return get( path, query ?? {} ).then(
			( data ) => {
				setState( { data, error: null, loading: false } );

				return data;
			},
			( error ) =>
				setState( ( previous ) => ( {
					...previous,
					error: errorMessage( error ),
					loading: false,
				} ) )
		);
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ path, queryKey ] );

	useEffect( () => {
		reload();
	}, [ reload ] );

	const setData = ( data ) =>
		setState( ( previous ) => ( { ...previous, data } ) );

	return { ...state, reload, setData };
};

/**
 * Short messages at the corner of the screen.
 *
 * @return {Array} [ toast element, show( message, tone ) ]
 */
export const useToast = () => {
	const [ toast, setToast ] = useState( null );
	const timer = useRef();

	const show = useCallback( ( message, tone = 'info' ) => {
		clearTimeout( timer.current );
		setToast( { message, tone } );
		timer.current = setTimeout( () => setToast( null ), 5000 );
	}, [] );

	useEffect( () => () => clearTimeout( timer.current ), [] );

	const element = toast ? (
		<div
			className={
				'ms-toast' +
				( toast.tone === 'error' ? ' ms-toast--error' : '' )
			}
			role={ toast.tone === 'error' ? 'alert' : 'status' }
		>
			{ toast.message }
		</div>
	) : null;

	return [ element, show ];
};

/* Activity log */

const quoted = ( text ) =>
	sprintf(
		/* translators: %s: a post title or a term name, quoted */
		__( '“%s”', 'meiliscout' ),
		text
	);

/**
 * What an activity entry was, in words.
 *
 * @param {Object} entry
 * @return {string} The description.
 */
export const describeOperation = ( entry ) => {
	const label = entry.label ? quoted( entry.label ) : '';

	switch ( entry.operation ) {
		case 'full.update':
			return __( 'Full indexation · update', 'meiliscout' );
		case 'full.rebuild':
			return __( 'Full indexation · rebuild', 'meiliscout' );
		case 'post.index':
			/* translators: %s: the post title */
			return sprintf( __( 'Content saved · %s', 'meiliscout' ), label );
		case 'post.remove':
			/* translators: %s: the post title */
			return sprintf( __( 'Content removed · %s', 'meiliscout' ), label );
		case 'term.index':
			/* translators: %s: the term name */
			return sprintf( __( 'Term saved · %s', 'meiliscout' ), label );
		case 'term.remove':
			/* translators: %s: the term name */
			return sprintf( __( 'Term deleted · %s', 'meiliscout' ), label );
		case 'posts_for_term.reindex':
			return sprintf(
				/* translators: %s: the term name */
				__(
					'Term renamed, its contents re-indexed · %s',
					'meiliscout'
				),
				label
			);
		default:
			return entry.operation;
	}
};

export const StatusPill = ( { status } ) =>
	status === 'success' ? (
		<Pill tone="success">{ __( 'Succeeded', 'meiliscout' ) }</Pill>
	) : (
		<Pill tone="error">{ __( 'Failed', 'meiliscout' ) }</Pill>
	);

export const ActivityTable = ( { entries, showKind = false, onRetried } ) => {
	const [ retrying, setRetrying ] = useState( null );
	const [ toast, showToast ] = useToast();

	const retry = ( entry ) => {
		setRetrying( entry.id );
		post( `/activity/${ entry.id }/retry` )
			.then( ( result ) => {
				showToast(
					result.status === 'success'
						? __(
								'Done: the change reached Meilisearch.',
								'meiliscout'
						  )
						: __( 'It failed again: see the log.', 'meiliscout' ),
					result.status === 'success' ? 'info' : 'error'
				);
				onRetried?.();
			} )
			.catch( ( error ) => showToast( errorMessage( error ), 'error' ) )
			.finally( () => setRetrying( null ) );
	};

	if ( ! entries.length ) {
		return (
			<p className="ms-empty">
				{ __( 'Nothing indexed yet.', 'meiliscout' ) }
			</p>
		);
	}

	const columns = showKind ? 6 : 5;

	return (
		<div className="ms-table-wrap">
			{ toast }
			<table className="ms-table">
				<thead>
					<tr>
						<th scope="col">{ __( 'Status', 'meiliscout' ) }</th>
						<th scope="col">{ __( 'Operation', 'meiliscout' ) }</th>
						{ showKind && (
							<th scope="col">{ __( 'Mode', 'meiliscout' ) }</th>
						) }
						<th scope="col">{ __( 'Items', 'meiliscout' ) }</th>
						<th scope="col">{ __( 'Duration', 'meiliscout' ) }</th>
						<th scope="col">{ __( 'Date', 'meiliscout' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ entries.map( ( entry ) => [
						<tr key={ entry.id }>
							<td>
								<StatusPill status={ entry.status } />
							</td>
							<td>{ describeOperation( entry ) }</td>
							{ showKind && (
								<td className="ms-table__muted">
									{ entry.kind === 'full'
										? __( 'Full', 'meiliscout' )
										: __( 'Real time', 'meiliscout' ) }
								</td>
							) }
							<td className="ms-table__num">
								{ number( entry.items ) }
							</td>
							<td className="ms-table__num">
								{ duration( entry.duration_ms ) }
							</td>
							<td className="ms-table__muted">
								{ ago( entry.time ) }
							</td>
						</tr>,
						entry.status === 'error' && (
							<tr
								key={ entry.id + '-error' }
								className="ms-table__detail"
							>
								<td colSpan={ columns }>
									{ entry.error }{ ' ' }
									{ entry.retryable && (
										<button
											type="button"
											className="ms-link-button"
											disabled={ retrying === entry.id }
											onClick={ () => retry( entry ) }
										>
											{ retrying === entry.id
												? __(
														'Retrying…',
														'meiliscout'
												  )
												: __(
														'Retry now',
														'meiliscout'
												  ) }
										</button>
									) }
									{ entry.retried && (
										<span>
											{ __( '(retried)', 'meiliscout' ) }
										</span>
									) }
								</td>
							</tr>
						),
					] ) }
				</tbody>
			</table>
		</div>
	);
};
