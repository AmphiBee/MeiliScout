import {
	createInterpolateElement,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

import { get, post, errorMessage } from '../api';
import { Banner, Pill, Skeleton, useResource, useToast } from '../components';
import { agoIso } from '../format';

const EMPTY = {
	id: null,
	listing: '',
	locale: '',
	parts: {},
	title: '',
	description: '',
	h1: '',
	intro: '',
	faq: [],
};

const REASONS = {
	filters: __(
		'A filter outside the path (a parameter, a range): never indexed.',
		'meiliscout'
	),
	values: __( 'Several values of one facet.', 'meiliscout' ),
	depth: __( 'More facets than the listing indexes.', 'meiliscout' ),
	few: __( 'Fewer results than the listing indexes.', 'meiliscout' ),
	search: __( 'A search is never indexed.', 'meiliscout' ),
	sort: __( 'Another sort than the default one.', 'meiliscout' ),
	empty: __( 'No results.', 'meiliscout' ),
};

/**
 * A rule's key from the form's facets, in the listing's order.
 *
 * @param {Object} listing
 * @param {Object} parts   Value by facet key: a term id or *.
 * @return {string} The key.
 */
const keyOf = ( listing, parts ) =>
	( listing?.facets ?? [] )
		.filter( ( facet ) => parts[ facet.key ] )
		.map( ( facet ) => facet.key + '=' + parts[ facet.key ] )
		.join( '|' );

const partsOf = ( key ) =>
	Object.fromEntries(
		key
			.split( '|' )
			.filter( Boolean )
			.map( ( part ) => part.split( '=' ) )
	);

const download = ( filename, text ) => {
	const url = URL.createObjectURL(
		new Blob( [ text ], { type: 'text/csv;charset=utf-8' } )
	);
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = filename;
	link.click();
	URL.revokeObjectURL( url );
};

const Variables = ( { listing } ) => (
	<span className="ms-field__help ms-seo-help">
		{ createInterpolateElement(
			__(
				'Variables: <code>{site}</code>, <code>{title}</code> (the listing’s page), <code>{page}</code>, <code>{pages}</code>, <code>{total}</code>, and each facet’s term:',
				'meiliscout'
			),
			{ code: <code /> }
		) }{ ' ' }
		{ ( listing?.facets ?? [] ).map( ( facet, index ) => (
			<span key={ facet.key }>
				{ index > 0 && ', ' }
				<code>{ '{' + facet.key + '}' }</code>
			</span>
		) ) }
		.{ ' ' }
		{ __(
			'Past the first page, the title says which page it is, unless it holds {page}.',
			'meiliscout'
		) }
	</span>
);

const RuleForm = ( { data, initial, onSaved, onCancel } ) => {
	const [ form, setForm ] = useState( initial );
	const formRef = useRef();
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( null );
	const listing = data.listings.find( ( item ) => item.id === form.listing );
	const set = ( changes ) => setForm( { ...form, ...changes } );
	const chosen = Object.values( form.parts ).filter( Boolean ).length;

	// The form opens below the preview: bring it into view
	useEffect( () => {
		formRef.current?.scrollIntoView( {
			block: 'start',
			behavior: 'smooth',
		} );
	}, [] );

	const save = ( event ) => {
		event.preventDefault();
		setSaving( true );
		setError( null );
		post( '/listings/seo-rules', {
			id: form.id,
			listing: form.listing,
			locale: form.locale,
			key: keyOf( listing, form.parts ),
			title: form.title,
			description: form.description,
			h1: form.h1,
			intro: form.intro,
			faq: form.faq,
		} )
			.then( onSaved )
			.catch( ( reason ) => setError( errorMessage( reason ) ) )
			.finally( () => setSaving( false ) );
	};

	const setQuestion = ( index, changes ) =>
		set( {
			faq: form.faq.map( ( pair, i ) =>
				i === index ? { ...pair, ...changes } : pair
			),
		} );

	return (
		<form
			className="ms-card ms-seo-form"
			ref={ formRef }
			onSubmit={ save }
			aria-labelledby="ms-seo-form-title"
		>
			<div className="ms-card__head">
				<h2 id="ms-seo-form-title">
					{ form.id
						? __( 'Edit the rule', 'meiliscout' )
						: __( 'New rule', 'meiliscout' ) }
				</h2>
				<p>
					{ __(
						'A rule names the views of a listing: without filters, or with one term (or any term) of up to two facets. The most specific rule of the view’s language wins, then a rule for every language.',
						'meiliscout'
					) }
				</p>
			</div>
			<div className="ms-card__body">
				{ error && (
					<Banner tone="error" icon="alert">
						<p>{ error }</p>
					</Banner>
				) }
				<div className="ms-form-grid">
					<div className="ms-field">
						<label htmlFor="ms-seo-listing">
							{ __( 'Listing', 'meiliscout' ) }
						</label>
						<select
							id="ms-seo-listing"
							className="ms-input"
							value={ form.listing }
							required
							onChange={ ( event ) =>
								set( {
									listing: event.target.value,
									parts: {},
								} )
							}
						>
							<option value="">
								{ __( 'Choose…', 'meiliscout' ) }
							</option>
							{ data.listings.map( ( item ) => (
								<option key={ item.id } value={ item.id }>
									{ item.id }
								</option>
							) ) }
						</select>
					</div>
					<div className="ms-field">
						<label htmlFor="ms-seo-locale">
							{ __( 'Language', 'meiliscout' ) }
						</label>
						<select
							id="ms-seo-locale"
							className="ms-input"
							value={ form.locale }
							onChange={ ( event ) =>
								set( { locale: event.target.value } )
							}
						>
							<option value="">
								{ __( 'Every language', 'meiliscout' ) }
							</option>
							{ data.locales.map( ( locale ) => (
								<option key={ locale } value={ locale }>
									{ locale }
								</option>
							) ) }
						</select>
					</div>
					{ listing?.facets.map( ( facet ) => {
						const id = 'ms-seo-facet-' + facet.key;
						const value = form.parts[ facet.key ] || '';

						return (
							<div className="ms-field" key={ facet.key }>
								<label htmlFor={ id }>{ facet.label }</label>
								<select
									id={ id }
									className="ms-input"
									value={ value }
									disabled={
										! value && chosen >= listing.depth
									}
									onChange={ ( event ) =>
										set( {
											parts: {
												...form.parts,
												[ facet.key ]:
													event.target.value,
											},
										} )
									}
								>
									<option value="">
										{ __( 'Not filtered', 'meiliscout' ) }
									</option>
									<option value="*">
										{ __( 'Any term', 'meiliscout' ) }
									</option>
									{ facet.terms.map( ( term ) => (
										<option
											key={ term.id }
											value={ String( term.id ) }
										>
											{ term.name }
										</option>
									) ) }
								</select>
							</div>
						);
					} ) }
				</div>
				{ listing && ! listing.facets.length && (
					<p className="ms-field__help">
						{ __(
							'This listing has no taxonomy facet: its rules name it without filters.',
							'meiliscout'
						) }
					</p>
				) }

				<div className="ms-field">
					<label htmlFor="ms-seo-title">
						{ __( 'Title', 'meiliscout' ) }
					</label>
					<input
						id="ms-seo-title"
						className="ms-input"
						value={ form.title }
						onChange={ ( event ) =>
							set( { title: event.target.value } )
						}
						aria-describedby="ms-seo-variables"
					/>
					<div id="ms-seo-variables">
						<Variables listing={ listing } />
					</div>
				</div>
				<div className="ms-field">
					<label htmlFor="ms-seo-description">
						{ __( 'Meta description', 'meiliscout' ) }
					</label>
					<textarea
						id="ms-seo-description"
						className="ms-input"
						rows={ 2 }
						value={ form.description }
						onChange={ ( event ) =>
							set( { description: event.target.value } )
						}
					/>
				</div>
				<div className="ms-field">
					<label htmlFor="ms-seo-h1">
						{ __( 'Heading', 'meiliscout' ) }
					</label>
					<input
						id="ms-seo-h1"
						className="ms-input"
						value={ form.h1 }
						onChange={ ( event ) =>
							set( { h1: event.target.value } )
						}
						aria-describedby="ms-seo-h1-help"
					/>
					<span id="ms-seo-h1-help" className="ms-field__help">
						{ __(
							'Printed as the listing’s h1 by its introduction: leave it empty when the page already has its own.',
							'meiliscout'
						) }
					</span>
				</div>
				<div className="ms-field">
					<label htmlFor="ms-seo-intro">
						{ __( 'Introduction', 'meiliscout' ) }
					</label>
					<textarea
						id="ms-seo-intro"
						className="ms-input"
						rows={ 4 }
						value={ form.intro }
						onChange={ ( event ) =>
							set( { intro: event.target.value } )
						}
						aria-describedby="ms-seo-intro-help"
					/>
					<span id="ms-seo-intro-help" className="ms-field__help">
						{ __(
							'Above the listing, on its first page. Simple HTML is kept.',
							'meiliscout'
						) }
					</span>
				</div>

				<fieldset className="ms-field ms-seo-faq">
					<legend className="ms-field__label">
						{ __( 'Questions', 'meiliscout' ) }
					</legend>
					{ form.faq.map( ( pair, index ) => (
						<div className="ms-seo-faq__pair" key={ index }>
							<input
								className="ms-input"
								value={ pair.question }
								placeholder={ __( 'Question', 'meiliscout' ) }
								aria-label={ sprintf(
									/* translators: %d: a question's number */
									__( 'Question %d', 'meiliscout' ),
									index + 1
								) }
								onChange={ ( event ) =>
									setQuestion( index, {
										question: event.target.value,
									} )
								}
							/>
							<textarea
								className="ms-input"
								rows={ 2 }
								value={ pair.answer }
								placeholder={ __( 'Answer', 'meiliscout' ) }
								aria-label={ sprintf(
									/* translators: %d: a question's number */
									__( 'Answer %d', 'meiliscout' ),
									index + 1
								) }
								onChange={ ( event ) =>
									setQuestion( index, {
										answer: event.target.value,
									} )
								}
							/>
							<button
								type="button"
								className="ms-button ms-button--small"
								onClick={ () =>
									set( {
										faq: form.faq.filter(
											( unused, i ) => i !== index
										),
									} )
								}
							>
								{ __( 'Remove', 'meiliscout' ) }
							</button>
						</div>
					) ) }
					<div>
						<button
							type="button"
							className="ms-button ms-button--small"
							onClick={ () =>
								set( {
									faq: [
										...form.faq,
										{ question: '', answer: '' },
									],
								} )
							}
						>
							{ __( 'Add a question', 'meiliscout' ) }
						</button>
					</div>
					<span className="ms-field__help">
						{ __(
							'Below the listing, on its first page, and as FAQPage structured data when the view is indexable.',
							'meiliscout'
						) }
					</span>
				</fieldset>
			</div>
			<div className="ms-card__foot">
				<button
					type="button"
					className="ms-button"
					onClick={ onCancel }
				>
					{ __( 'Cancel', 'meiliscout' ) }
				</button>
				<button
					type="submit"
					className="ms-button ms-button--primary"
					disabled={ saving || ! form.listing }
				>
					{ saving
						? __( 'Saving…', 'meiliscout' )
						: __( 'Save the rule', 'meiliscout' ) }
				</button>
			</div>
		</form>
	);
};

const Line = ( { label, children } ) => (
	<div className="ms-row">
		<div className="ms-row__label">
			<strong>{ label }</strong>
		</div>
		<div className="ms-seo-preview__value">{ children }</div>
	</div>
);

const UrlPreview = ( { listings } ) => {
	const first = listings.find( ( listing ) => listing.url );
	const [ url, setUrl ] = useState( first?.url ?? '' );
	const [ view, setView ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ loading, setLoading ] = useState( false );

	const run = ( event ) => {
		event.preventDefault();
		setLoading( true );
		setError( null );
		get( '/listings/seo-rules/preview', { url } )
			.then( setView )
			.catch( ( reason ) => {
				setView( null );
				setError( errorMessage( reason ) );
			} )
			.finally( () => setLoading( false ) );
	};

	const none = <span className="ms-table__muted">—</span>;

	return (
		<section className="ms-card" aria-labelledby="ms-seo-preview-title">
			<div className="ms-card__head">
				<h2 id="ms-seo-preview-title">
					{ __( 'Preview a URL', 'meiliscout' ) }
				</h2>
				<p>
					{ __(
						'What a URL of a listing tells search engines, and the rule it takes.',
						'meiliscout'
					) }
				</p>
			</div>
			<form className="ms-card__body" onSubmit={ run }>
				<div className="ms-field__row">
					<label htmlFor="ms-seo-url" className="ms-visually-hidden">
						{ __( 'URL', 'meiliscout' ) }
					</label>
					<input
						id="ms-seo-url"
						className="ms-input"
						type="text"
						value={ url }
						onChange={ ( event ) => setUrl( event.target.value ) }
						placeholder="/projects/page/2/?type=refonte"
					/>
					<button
						type="submit"
						className="ms-button"
						disabled={ loading || ! url }
					>
						{ loading
							? __( 'Reading…', 'meiliscout' )
							: __( 'Preview', 'meiliscout' ) }
					</button>
				</div>
				{ error && (
					<Banner tone="error" icon="alert">
						<p>{ error }</p>
					</Banner>
				) }
			</form>
			{ view && (
				<div className="ms-seo-preview" aria-live="polite">
					<Line label={ __( 'Search engines', 'meiliscout' ) }>
						{ view.not_found && (
							<Pill tone="error">
								{ __(
									'404: past the last page',
									'meiliscout'
								) }
							</Pill>
						) }
						{ ! view.not_found && view.indexable && (
							<Pill tone="success">
								{ __( 'Indexable', 'meiliscout' ) }
							</Pill>
						) }
						{ ! view.not_found && ! view.indexable && (
							<>
								<Pill tone="warning">noindex</Pill>{ ' ' }
								{ REASONS[ view.reason ] }
							</>
						) }
						{ ! view.seo && (
							<p className="ms-field__help">
								{ __(
									'This listing turned its SEO off (seo: false): the SEO plugin decides.',
									'meiliscout'
								) }
							</p>
						) }
					</Line>
					<Line label={ __( 'Robots', 'meiliscout' ) }>
						{ view.robots ? <code>{ view.robots }</code> : none }
					</Line>
					<Line label={ __( 'Canonical', 'meiliscout' ) }>
						{ view.canonical ? (
							<code>{ view.canonical }</code>
						) : (
							none
						) }
					</Line>
					<Line label={ __( 'Previous, next', 'meiliscout' ) }>
						{ view.prev || view.next ? (
							<>
								<code>{ view.prev || '—' }</code>
								<br />
								<code>{ view.next || '—' }</code>
							</>
						) : (
							none
						) }
					</Line>
					<Line label={ __( 'Results', 'meiliscout' ) }>
						{ sprintf(
							/* translators: 1: number of results, 2: page, 3: number of pages */
							_n(
								'%1$d result, page %2$d of %3$d',
								'%1$d results, page %2$d of %3$d',
								view.total,
								'meiliscout'
							),
							view.total,
							view.page,
							view.pages
						) }
					</Line>
					<Line label={ __( 'Rule', 'meiliscout' ) }>
						{ view.rule ? view.rule_label : none }
					</Line>
					<Line label={ __( 'Title', 'meiliscout' ) }>
						{ view.title || __( 'The SEO plugin’s', 'meiliscout' ) }
					</Line>
					<Line label={ __( 'Description', 'meiliscout' ) }>
						{ view.description ||
							__( 'The SEO plugin’s', 'meiliscout' ) }
					</Line>
					<Line label={ __( 'Structured data', 'meiliscout' ) }>
						{ view.structured_data.length
							? view.structured_data
									.map( ( piece ) => piece[ '@type' ] )
									.join( ', ' )
							: none }
					</Line>
					<Line label={ __( 'Written by', 'meiliscout' ) }>
						{ sprintf(
							/* translators: 1: an SEO plugin, 2: a locale */
							__( '%1$s, language %2$s', 'meiliscout' ),
							view.adapter,
							view.locale
						) }
					</Line>
				</div>
			) }
		</section>
	);
};

const SeoRules = () => {
	const resource = useResource( '/listings/seo-rules' );
	const [ editing, setEditing ] = useState( null );
	const [ filter, setFilter ] = useState( '' );
	const [ report, setReport ] = useState( null );
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

	const rules = data.rules.filter(
		( rule ) => ! filter || rule.listing === filter
	);

	const remove = ( rule ) => {
		apiFetch( {
			path: '/meiliscout/v1/listings/seo-rules/' + rule.id,
			method: 'DELETE',
		} )
			.then( ( fresh ) => {
				resource.setData( fresh );
				showToast( __( 'Rule deleted.', 'meiliscout' ) );
			} )
			.catch( ( error ) => showToast( errorMessage( error ), 'error' ) );
	};

	// To the multilingual plugin's other languages
	const duplicate = ( rule ) =>
		post( `/listings/seo-rules/${ rule.id }/duplicate` )
			.then( ( answer ) => {
				resource.setData( answer );
				const { created, skipped } = answer.duplicated;
				showToast(
					created.length
						? sprintf(
								/* translators: %s: locales */
								__(
									'Copied to %s: translate its text.',
									'meiliscout'
								),
								created.join( ', ' )
						  )
						: Object.entries( skipped )
								.map(
									( [ locale, reason ] ) =>
										locale + ' : ' + reason
								)
								.join( ' ' ),
					created.length ? 'info' : 'error'
				);
			} )
			.catch( ( error ) => showToast( errorMessage( error ), 'error' ) );

	const exportCsv = () =>
		apiFetch( {
			path: addQueryArgs( '/meiliscout/v1/listings/seo-rules/export', {
				listing: filter,
			} ),
		} )
			.then( ( answer ) => download( answer.filename, answer.csv ) )
			.catch( ( error ) => showToast( errorMessage( error ), 'error' ) );

	const importCsv = ( event ) => {
		const file = event.target.files?.[ 0 ];
		event.target.value = '';
		if ( ! file ) {
			return;
		}
		file.text()
			.then( ( csv ) => post( '/listings/seo-rules/import', { csv } ) )
			.then( ( answer ) => {
				setReport( answer.report );
				resource.setData( answer );
			} )
			.catch( ( error ) => showToast( errorMessage( error ), 'error' ) );
	};

	const edit = ( rule ) =>
		setEditing( {
			...EMPTY,
			opened: Date.now(),
			...rule,
			parts: partsOf( rule.key ),
			faq: rule.faq ?? [],
		} );

	return (
		<div className="ms-wrap ms-page" style={ { gap: 20 } }>
			{ toast }
			<UrlPreview listings={ data.listings } />

			{ editing && (
				<RuleForm
					// A fresh form each time one opens
					key={ editing.opened }
					data={ data }
					initial={ editing }
					onCancel={ () => setEditing( null ) }
					onSaved={ ( fresh ) => {
						resource.setData( fresh );
						setEditing( null );
						showToast( __( 'Rule saved.', 'meiliscout' ) );
					} }
				/>
			) }

			{ report && (
				<Banner
					tone={ report.errors.length ? 'error' : 'info' }
					icon={ report.errors.length ? 'alert' : 'info' }
					title={ sprintf(
						/* translators: 1: rules created, 2: rules replaced */
						__(
							'Import: %1$d created, %2$d replaced',
							'meiliscout'
						),
						report.created,
						report.updated
					) }
					actions={
						<button
							type="button"
							className="ms-button ms-button--small"
							onClick={ () => setReport( null ) }
						>
							{ __( 'Dismiss', 'meiliscout' ) }
						</button>
					}
				>
					{ report.errors.length > 0 && (
						<ul>
							{ report.errors.map( ( error ) => (
								<li key={ error.line }>
									{ sprintf(
										/* translators: 1: a line number, 2: an error */
										__( 'Line %1$d: %2$s', 'meiliscout' ),
										error.line,
										error.message
									) }
								</li>
							) ) }
						</ul>
					) }
				</Banner>
			) }

			<section className="ms-card" aria-labelledby="ms-seo-rules-title">
				<div className="ms-card__head ms-card__head--row">
					<div>
						<h2 id="ms-seo-rules-title">
							{ __( 'SEO rules', 'meiliscout' ) }
						</h2>
						<p>
							{ __(
								'Titles, descriptions, headings, introductions and questions of the listings’ views.',
								'meiliscout'
							) }
						</p>
					</div>
					<div className="ms-seo-actions">
						<label
							htmlFor="ms-seo-filter"
							className="ms-visually-hidden"
						>
							{ __( 'Listing', 'meiliscout' ) }
						</label>
						<select
							id="ms-seo-filter"
							className="ms-input"
							value={ filter }
							onChange={ ( event ) =>
								setFilter( event.target.value )
							}
						>
							<option value="">
								{ __( 'Every listing', 'meiliscout' ) }
							</option>
							{ data.listings.map( ( listing ) => (
								<option key={ listing.id } value={ listing.id }>
									{ listing.id }
								</option>
							) ) }
						</select>
						<button
							type="button"
							className="ms-button"
							onClick={ exportCsv }
						>
							{ __( 'Export CSV', 'meiliscout' ) }
						</button>
						<label className="ms-button">
							{ __( 'Import CSV', 'meiliscout' ) }
							<input
								type="file"
								accept=".csv,text/csv"
								className="ms-visually-hidden"
								onChange={ importCsv }
							/>
						</label>
						<button
							type="button"
							className="ms-button ms-button--primary"
							disabled={ ! data.listings.length }
							onClick={ () =>
								setEditing( {
									...EMPTY,
									opened: Date.now(),
									listing: filter || data.listings[ 0 ]?.id,
									locale: data.locale,
								} )
							}
						>
							{ __( 'New rule', 'meiliscout' ) }
						</button>
					</div>
				</div>
				{ ! data.listings.length && (
					<p className="ms-empty">
						{ __(
							'No listing is declared: rules name a listing’s views.',
							'meiliscout'
						) }
					</p>
				) }
				{ data.listings.length > 0 && ! rules.length && (
					<p className="ms-empty">
						{ __( 'No rule yet.', 'meiliscout' ) }
					</p>
				) }
				{ rules.length > 0 && (
					<div className="ms-table-wrap">
						<table className="ms-table">
							<thead>
								<tr>
									<th scope="col">
										{ __( 'Listing', 'meiliscout' ) }
									</th>
									<th scope="col">
										{ __( 'Language', 'meiliscout' ) }
									</th>
									<th scope="col">
										{ __( 'View', 'meiliscout' ) }
									</th>
									<th scope="col">
										{ __( 'Title', 'meiliscout' ) }
									</th>
									<th scope="col">
										{ __( 'Updated', 'meiliscout' ) }
									</th>
									<th scope="col">
										<span className="ms-visually-hidden">
											{ __( 'Actions', 'meiliscout' ) }
										</span>
									</th>
								</tr>
							</thead>
							<tbody>
								{ rules.map( ( rule ) => (
									<tr key={ rule.id }>
										<td>{ rule.listing }</td>
										<td className="ms-table__muted">
											{ rule.locale ||
												__( 'Every', 'meiliscout' ) }
										</td>
										<td>{ rule.key_label }</td>
										<td>{ rule.title || '—' }</td>
										<td className="ms-table__muted">
											{ rule.updated_at &&
												// Stored in UTC
												agoIso(
													rule.updated_at.replace(
														' ',
														'T'
													) + 'Z'
												) }
										</td>
										<td className="ms-seo-rule-actions">
											<button
												type="button"
												className="ms-link-button"
												onClick={ () => edit( rule ) }
											>
												{ __( 'Edit', 'meiliscout' ) }
											</button>
											{ data.languages.length > 1 &&
												rule.locale && (
													<button
														type="button"
														className="ms-link-button"
														onClick={ () =>
															duplicate( rule )
														}
													>
														{ __(
															'Copy to translations',
															'meiliscout'
														) }
													</button>
												) }
											<button
												type="button"
												className="ms-link-button"
												onClick={ () => remove( rule ) }
											>
												{ __( 'Delete', 'meiliscout' ) }
											</button>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) }
			</section>
		</div>
	);
};

export default SeoRules;
