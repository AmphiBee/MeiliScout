import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { post, errorMessage } from '../api';
import { agoIso } from '../format';
import {
	Banner,
	Icon,
	Pill,
	Skeleton,
	Switch,
	useResource,
	useToast,
} from '../components';

/* Settings › Export / Import: the configuration in a file, and a file back. */

const isList = ( value ) => Array.isArray( value );
const isMap = ( value ) =>
	value !== null && typeof value === 'object' && ! Array.isArray( value );

const word = ( value ) => {
	if ( value === true ) {
		return __( 'On', 'meiliscout' );
	}
	if ( value === false ) {
		return __( 'Off', 'meiliscout' );
	}
	if ( value === null || value === undefined || value === '' ) {
		return __( 'None', 'meiliscout' );
	}
	return String( value );
};

/**
 * What a setting is going from and to: lists as what is added and removed,
 * maps as the switches that change, the rest as before and after.
 *
 * @param {Object} props
 * @param {Object} props.row One row of a section's plan.
 */
const Change = ( { row } ) => {
	// PHP writes an empty map as an empty list
	const blank = ( value, other ) =>
		isList( value ) && value.length === 0 && isMap( other ) ? {} : value;
	const before = blank( row.before, row.after );
	const after = blank( row.after, row.before );

	if ( isList( before ) && isList( after ) ) {
		const added = after.filter( ( item ) => ! before.includes( item ) );
		const removed = before.filter( ( item ) => ! after.includes( item ) );
		const reordered =
			added.length === 0 &&
			removed.length === 0 &&
			row.status === 'changed';
		const kept = after.length - added.length;

		return (
			<span className="ms-diff__value">
				{ removed.map( ( item ) => (
					<span key={ 'r' + item } className="ms-diff__chip is-out">
						{ item }
					</span>
				) ) }
				{ added.map( ( item ) => (
					<span key={ 'a' + item } className="ms-diff__chip is-in">
						{ item }
					</span>
				) ) }
				{ reordered && (
					<span className="ms-diff__note">
						{ __( 'Same fields, other order', 'meiliscout' ) }
					</span>
				) }
				{ kept > 0 && (
					<span className="ms-diff__note">
						{ sprintf(
							/* translators: %d: how many values stay as they are */
							_n(
								'%d unchanged',
								'%d unchanged',
								kept,
								'meiliscout'
							),
							kept
						) }
					</span>
				) }
				{ after.length === 0 && removed.length === 0 && (
					<span className="ms-diff__note">
						{ __( 'None', 'meiliscout' ) }
					</span>
				) }
			</span>
		);
	}

	if ( isMap( before ) && isMap( after ) ) {
		const keys = [
			...new Set( [ ...Object.keys( before ), ...Object.keys( after ) ] ),
		];
		const changed = keys.filter(
			( key ) => before[ key ] !== after[ key ]
		);

		return (
			<span className="ms-diff__value">
				{ changed.map( ( key ) => (
					<span key={ key } className="ms-diff__pair">
						<code>{ key }</code>
						<span className="ms-diff__from">
							{ word( before[ key ] ) }
						</span>
						<Icon
							name="arrow"
							size={ 12 }
							className="ms-diff__arrow"
						/>
						<span className="ms-diff__to">
							{ word( after[ key ] ) }
						</span>
					</span>
				) ) }
				{ changed.length === 0 && (
					<span className="ms-diff__note">
						{ sprintf(
							/* translators: %d: how many settings stay as they are */
							_n(
								'%d setting, unchanged',
								'%d settings, unchanged',
								keys.length,
								'meiliscout'
							),
							keys.length
						) }
					</span>
				) }
			</span>
		);
	}

	return (
		<span className="ms-diff__value">
			<span className="ms-diff__from">{ word( before ) }</span>
			<Icon name="arrow" size={ 12 } className="ms-diff__arrow" />
			<span className="ms-diff__to">{ word( after ) }</span>
		</span>
	);
};

const STATUS = {
	changed: { tone: 'info', label: () => __( 'Will change', 'meiliscout' ) },
	unchanged: {
		tone: 'outline',
		label: () => __( 'Same as here', 'meiliscout' ),
	},
	error: { tone: 'error', label: () => __( 'Cannot import', 'meiliscout' ) },
	skipped: { tone: 'warning', label: () => __( 'Skipped', 'meiliscout' ) },
};

/**
 * One section of a previewed file: its pill, what changes, what is wrong.
 *
 * @param {Object}   props
 * @param {Object}   props.section  The section's plan.
 * @param {boolean}  props.selected Whether it will be imported.
 * @param {Function} props.onToggle
 * @param {boolean}  props.disabled
 */
const SectionDiff = ( { section, selected, onToggle, disabled } ) => {
	const status = STATUS[ section.status ] ?? STATUS.unchanged;
	const importable = section.status === 'changed';
	const shown = section.rows.filter( ( row ) => row.status !== 'unchanged' );
	const same = section.rows.length - shown.length;

	return (
		<section
			className={
				'ms-diff' +
				( importable && selected ? ' is-selected' : '' ) +
				( importable ? '' : ' is-inert' )
			}
		>
			<header className="ms-diff__head">
				<label className="ms-diff__title">
					<input
						type="checkbox"
						checked={ importable && selected }
						disabled={ ! importable || disabled }
						onChange={ onToggle }
					/>
					<strong>{ section.label }</strong>
				</label>
				<span className="ms-diff__flags">
					{ section.reindex && (
						<Pill tone="warning">
							{ __( 'Needs a rebuild', 'meiliscout' ) }
						</Pill>
					) }
					<Pill tone={ status.tone }>{ status.label() }</Pill>
				</span>
			</header>

			{ section.skipped && (
				<p className="ms-diff__line">{ section.skipped }</p>
			) }

			{ shown.length > 0 && (
				<dl className="ms-diff__rows">
					{ shown.map( ( row ) => (
						<div
							key={ row.field }
							className={ 'ms-diff__row is-' + row.status }
						>
							<dt>{ row.label }</dt>
							<dd>
								<Change row={ row } />
								{ row.note && (
									<span className="ms-diff__note">
										{ row.note }
									</span>
								) }
							</dd>
						</div>
					) ) }
				</dl>
			) }

			{ importable && same > 0 && (
				<p className="ms-diff__line">
					{ sprintf(
						/* translators: %d: how many settings of the file this site already has */
						_n(
							'%d other setting is the same on this site.',
							'%d other settings are the same on this site.',
							same,
							'meiliscout'
						),
						same
					) }
				</p>
			) }

			{ section.errors.map( ( message ) => (
				<p key={ message } className="ms-diff__line is-error">
					<Icon name="alert" size={ 14 } /> { message }
				</p>
			) ) }
			{ section.warnings.map( ( message ) => (
				<p key={ message } className="ms-diff__line is-warning">
					<Icon name="info" size={ 14 } /> { message }
				</p>
			) ) }
		</section>
	);
};

/**
 * The export card: pick what goes in the file, download it.
 *
 * @param {Object} props
 * @param {Array}  props.sections What the site can export.
 */
const ExportCard = ( { sections } ) => {
	const [ chosen, setChosen ] = useState( null );
	const [ includeEnv, setIncludeEnv ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ toast, showToast ] = useToast();

	const available = sections.filter( ( section ) => ! section.unavailable );
	const picked = chosen ?? available.map( ( section ) => section.key );

	const toggle = ( key ) =>
		setChosen(
			picked.includes( key )
				? picked.filter( ( item ) => item !== key )
				: [ ...picked, key ]
		);

	const download = () => {
		setBusy( true );
		post( '/config/export', {
			sections: picked,
			include_env: includeEnv,
		} )
			.then( ( { filename, document } ) => {
				const blob = new window.Blob(
					[ JSON.stringify( document, null, 2 ) + '\n' ],
					{ type: 'application/json' }
				);
				const link = window.document.createElement( 'a' );
				link.href = window.URL.createObjectURL( blob );
				link.download = filename;
				window.document.body.appendChild( link );
				link.click();
				link.remove();
				window.setTimeout(
					() => window.URL.revokeObjectURL( link.href ),
					1000
				);
				showToast(
					sprintf(
						/* translators: %s: a file name */
						__( '%s downloaded.', 'meiliscout' ),
						filename
					)
				);
			} )
			.catch( ( error ) => showToast( errorMessage( error ), 'error' ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<section
			className="ms-card ms-transfer__card"
			aria-labelledby="ms-export-title"
		>
			{ toast }
			<div className="ms-card__head">
				<h3 id="ms-export-title">
					<Icon name="download" size={ 16 } />{ ' ' }
					{ __( 'Export', 'meiliscout' ) }
				</h3>
				<p>
					{ __(
						'The configuration in one file, to import on another site or keep in Git.',
						'meiliscout'
					) }
				</p>
			</div>
			<div className="ms-card__body">
				<fieldset className="ms-picks">
					<legend className="ms-visually-hidden">
						{ __( 'What the file holds', 'meiliscout' ) }
					</legend>
					{ sections.map( ( section ) => (
						<label
							key={ section.key }
							className={
								'ms-pick' +
								( section.unavailable ? ' is-disabled' : '' )
							}
						>
							<input
								type="checkbox"
								checked={
									! section.unavailable &&
									picked.includes( section.key )
								}
								disabled={ !! section.unavailable }
								onChange={ () => toggle( section.key ) }
							/>
							<span className="ms-pick__text">
								<span className="ms-pick__title">
									{ section.label }
								</span>
								<span className="ms-pick__desc">
									{ section.unavailable ?? section.summary }
								</span>
							</span>
						</label>
					) ) }
				</fieldset>

				<div className="ms-row ms-transfer__env">
					<Switch
						checked={ includeEnv }
						onChange={ setIncludeEnv }
						label={ __(
							'Include the values of this server',
							'meiliscout'
						) }
					/>
					<div className="ms-row__label ms-row__label--text">
						<strong>
							{ __(
								'Include the values of this server',
								'meiliscout'
							) }
						</strong>
						<span>
							{ __(
								'The public URL of Meilisearch. Leave it off for a file you share or commit.',
								'meiliscout'
							) }
						</span>
					</div>
				</div>

				<p className="ms-transfer__safe">
					<Icon name="lock" size={ 14 } />{ ' ' }
					{ __(
						'Never in the file: the instance URL, the API keys, the listings key, the state of the indexes.',
						'meiliscout'
					) }
				</p>
			</div>
			<div className="ms-card__foot">
				<button
					type="button"
					className="ms-button ms-button--primary"
					onClick={ download }
					disabled={ busy || picked.length === 0 }
				>
					{ busy
						? __( 'Preparing…', 'meiliscout' )
						: __( 'Download the file', 'meiliscout' ) }
				</button>
			</div>
		</section>
	);
};

/**
 * The import card: a file in, what it would change, then apply.
 *
 * @param {Object}   props
 * @param {number}   props.maxBytes  The largest file read.
 * @param {Function} props.onApplied Called once something was written.
 */
const ImportCard = ( { maxBytes, onApplied } ) => {
	const [ phase, setPhase ] = useState( 'idle' );
	const [ file, setFile ] = useState( null );
	const [ plan, setPlan ] = useState( null );
	const [ picked, setPicked ] = useState( [] );
	const [ includeEnv, setIncludeEnv ] = useState( false );
	const [ dragging, setDragging ] = useState( false );
	const [ problem, setProblem ] = useState( null );
	const [ result, setResult ] = useState( null );

	const reset = () => {
		setPhase( 'idle' );
		setFile( null );
		setPlan( null );
		setPicked( [] );
		setIncludeEnv( false );
		setProblem( null );
		setResult( null );
	};

	const preview = ( text, name, withEnv ) => {
		setPhase( 'reading' );
		setProblem( null );

		return post( '/config/import/preview', {
			file: text,
			include_env: withEnv,
		} )
			.then( ( data ) => {
				setFile( { name, text } );
				setPlan( data );
				setPicked(
					data.sections
						.filter( ( section ) => section.status === 'changed' )
						.map( ( section ) => section.key )
				);
				setPhase( 'preview' );
			} )
			.catch( ( error ) => {
				setProblem( errorMessage( error ) );
				setPhase( 'idle' );
			} );
	};

	const read = ( chosen ) => {
		if ( ! chosen ) {
			return;
		}

		if ( chosen.size > maxBytes ) {
			setProblem(
				__(
					'This file is larger than 1 MB: it is not an export of MeiliScout.',
					'meiliscout'
				)
			);
			return;
		}

		chosen
			.text()
			.then( ( text ) => preview( text, chosen.name, includeEnv ) )
			.catch( () =>
				setProblem( __( 'The file could not be read.', 'meiliscout' ) )
			);
	};

	const apply = () => {
		setPhase( 'applying' );

		post( '/config/import/apply', {
			token: plan.token,
			sections: picked,
		} )
			.then( ( data ) => {
				setResult( data );
				setPhase( 'done' );
				onApplied();
			} )
			.catch( ( error ) => {
				setProblem( errorMessage( error ) );
				setPhase( 'preview' );
			} );
	};

	const toggle = ( key ) =>
		setPicked(
			picked.includes( key )
				? picked.filter( ( item ) => item !== key )
				: [ ...picked, key ]
		);

	const source = plan?.source ?? {};
	const reindex = plan
		? plan.sections.some(
				( section ) => picked.includes( section.key ) && section.reindex
		  )
		: false;

	return (
		<section
			className="ms-card ms-transfer__card"
			aria-labelledby="ms-import-title"
		>
			<div className="ms-card__head">
				<h3 id="ms-import-title">
					<Icon name="upload" size={ 16 } />{ ' ' }
					{ __( 'Import', 'meiliscout' ) }
				</h3>
				<p>
					{ __(
						'Read a file first: nothing is written until you apply it.',
						'meiliscout'
					) }
				</p>
			</div>

			<div className="ms-card__body" aria-live="polite">
				{ problem && (
					<Banner
						tone="error"
						icon="alert"
						title={ __(
							'This file cannot be imported',
							'meiliscout'
						) }
					>
						<p>{ problem }</p>
					</Banner>
				) }

				{ ( phase === 'idle' || phase === 'reading' ) && (
					<label
						className={
							'ms-drop' +
							( dragging ? ' is-dragging' : '' ) +
							( phase === 'reading' ? ' is-busy' : '' )
						}
						onDragOver={ ( event ) => {
							event.preventDefault();
							setDragging( true );
						} }
						onDragLeave={ () => setDragging( false ) }
						onDrop={ ( event ) => {
							event.preventDefault();
							setDragging( false );
							read( event.dataTransfer.files?.[ 0 ] );
						} }
					>
						<input
							type="file"
							accept="application/json,.json"
							className="ms-visually-hidden"
							onChange={ ( event ) => {
								read( event.target.files?.[ 0 ] );
								event.target.value = '';
							} }
							disabled={ phase === 'reading' }
						/>
						<Icon
							name={ phase === 'reading' ? 'refresh' : 'upload' }
							size={ 28 }
						/>
						<strong>
							{ phase === 'reading'
								? __( 'Reading the file…', 'meiliscout' )
								: __( 'Drop an export here', 'meiliscout' ) }
						</strong>
						<span>
							{ __(
								'or click to choose a .json file',
								'meiliscout'
							) }
						</span>
					</label>
				) }

				{ ( phase === 'preview' || phase === 'applying' ) && plan && (
					<>
						<div className="ms-transfer__file">
							<div>
								<strong>{ file.name }</strong>
								<span>
									{ [
										source.site,
										source.plugin_version &&
											sprintf(
												/* translators: %s: a version number */
												__(
													'MeiliScout %s',
													'meiliscout'
												),
												source.plugin_version
											),
										source.exported_at &&
											agoIso( source.exported_at ),
									]
										.filter( Boolean )
										.join( ' · ' ) }
								</span>
							</div>
							<button
								type="button"
								className="ms-link-button"
								onClick={ reset }
								disabled={ phase === 'applying' }
							>
								{ __( 'Choose another file', 'meiliscout' ) }
							</button>
						</div>

						{ plan.warnings.map( ( message ) => (
							<Banner
								key={ message }
								tone="attention"
								icon="info"
							>
								<p>{ message }</p>
							</Banner>
						) ) }

						{ source.has_environment && (
							<div className="ms-row ms-transfer__env">
								<Switch
									checked={ includeEnv }
									disabled={ phase === 'applying' }
									onChange={ ( on ) => {
										setIncludeEnv( on );
										preview( file.text, file.name, on );
									} }
									label={ __(
										'Also import the values of the server it came from',
										'meiliscout'
									) }
								/>
								<div className="ms-row__label ms-row__label--text">
									<strong>
										{ __(
											'Also import the values of the server it came from',
											'meiliscout'
										) }
									</strong>
									<span>
										{ __(
											'The public URL of Meilisearch. Usually different on this server: leave it off.',
											'meiliscout'
										) }
									</span>
								</div>
							</div>
						) }

						<div className="ms-diffs">
							{ plan.sections.map( ( section ) => (
								<SectionDiff
									key={ section.key }
									section={ section }
									selected={ picked.includes( section.key ) }
									onToggle={ () => toggle( section.key ) }
									disabled={ phase === 'applying' }
								/>
							) ) }
						</div>

						{ ! plan.sections.some(
							( section ) => section.status === 'changed'
						) && (
							<p className="ms-transfer__same">
								<Icon name="check" size={ 16 } />{ ' ' }
								{ __(
									'This site already has this configuration: there is nothing to import.',
									'meiliscout'
								) }
							</p>
						) }

						{ reindex && (
							<Banner
								tone="attention"
								icon="refresh"
								title={ __(
									'The index will be out of date',
									'meiliscout'
								) }
							>
								<p>
									{ __(
										'What is indexed or searched changes. Importing does not rebuild the index: run a Rebuild afterwards.',
										'meiliscout'
									) }
								</p>
							</Banner>
						) }
					</>
				) }

				{ phase === 'done' && result && (
					<div className="ms-transfer__done">
						<Banner
							tone="info"
							icon="check"
							title={ __(
								'Configuration imported',
								'meiliscout'
							) }
							actions={
								result.reindex && (
									<a
										className="ms-button ms-button--primary"
										href="#/indexation"
									>
										{ __(
											'Open Indexation',
											'meiliscout'
										) }
									</a>
								)
							}
						>
							<p>
								{ sprintf(
									/* translators: %d: how many sections were imported */
									_n(
										'%d section imported.',
										'%d sections imported.',
										result.applied.length,
										'meiliscout'
									),
									result.applied.length
								) }{ ' ' }
								{ result.reindex &&
									__(
										'Rebuild the index to apply what is indexed and searched.',
										'meiliscout'
									) }
							</p>
						</Banner>
					</div>
				) }
			</div>

			{ ( phase === 'preview' ||
				phase === 'applying' ||
				phase === 'done' ) && (
				<div className="ms-card__foot">
					{ phase === 'done' ? (
						<button
							type="button"
							className="ms-button"
							onClick={ reset }
						>
							{ __( 'Import another file', 'meiliscout' ) }
						</button>
					) : (
						<>
							<button
								type="button"
								className="ms-button"
								onClick={ reset }
								disabled={ phase === 'applying' }
							>
								{ __( 'Cancel', 'meiliscout' ) }
							</button>
							<button
								type="button"
								className="ms-button ms-button--primary"
								onClick={ apply }
								disabled={
									phase === 'applying' ||
									picked.length === 0 ||
									! plan.applicable
								}
							>
								{ phase === 'applying'
									? __( 'Importing…', 'meiliscout' )
									: sprintf(
											/* translators: %d: how many sections will be imported */
											_n(
												'Import %d section',
												'Import %d sections',
												picked.length,
												'meiliscout'
											),
											picked.length
									  ) }
							</button>
						</>
					) }
				</div>
			) }
		</section>
	);
};

/**
 * Settings › Export / Import.
 *
 * @param {Object}   props
 * @param {Function} props.onApplied Called once an import wrote something.
 */
const Transfer = ( { onApplied } ) => {
	const resource = useResource( '/config/sections' );

	return (
		<div id="transfer" className="ms-transfer">
			<div className="ms-section-head">
				<h2>{ __( 'Export and import', 'meiliscout' ) }</h2>
			</div>
			<p className="ms-transfer__lead">
				{ __(
					'Move the configuration to another site: staging to production, a client to the next. The content is moved by the database; rebuild the index on the new site.',
					'meiliscout'
				) }
			</p>

			{ resource.loading && <Skeleton height={ 220 } /> }
			{ resource.error && (
				<Banner tone="error" icon="alert">
					<p>{ errorMessage( resource.error ) }</p>
				</Banner>
			) }
			{ resource.data && (
				<div className="ms-transfer__cards">
					<ExportCard sections={ resource.data.sections } />
					<ImportCard
						maxBytes={ resource.data.max_bytes }
						onApplied={ () => {
							resource.reload();
							onApplied?.();
						} }
					/>
				</div>
			) }
		</div>
	);
};

export default Transfer;
